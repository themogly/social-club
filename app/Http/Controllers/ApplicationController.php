<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Http\Requests\SubmitApplicationRequest;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Support\ApplicationSpamGuard;
use App\Support\DocumentVault;
use App\Support\Settings;
use App\Support\Weight;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The tokenised invite → application form (prompt 04 invite, prompt 15 surface). This
 * is the ONE unauthenticated member-facing route: it is gated SOLELY by a valid,
 * unconsumed invite token (a SHA-256 hash lookup, never a guessable id), so a prospect
 * completes their pre-registration on their own phone. It writes only the application
 * PAYLOAD; the age gate, member creation and consent capture stay in the audited
 * approval action (App\Actions\Members\ApproveApplication) — this never duplicates them.
 */
class ApplicationController extends Controller
{
    public function show(string $token): View
    {
        $application = $this->find($token);

        if ($application === null) {
            abort(404);
        }

        if ($application->isInviteRevoked()) {
            return view('socio.application-closed', ['reason' => __('Esta invitación ha sido anulada.')]);
        }

        if ($application->isInviteExpired()) {
            return view('socio.application-closed', ['reason' => __('Esta invitación ha caducado. Pide una nueva a la asociación.')]);
        }

        // First view marks the invite "started" (for the Invitations status board).
        if ($application->opened_at === null) {
            $application->update(['opened_at' => now()]);
        }

        return view('socio.application', [
            'token' => $token,
            'application' => $application,
            'payload' => $application->payload ?? [],
            'formToken' => ApplicationSpamGuard::issueToken(),
        ]);
    }

    public function store(SubmitApplicationRequest $request, string $token): RedirectResponse
    {
        $application = $this->find($token);

        // Refuse a revoked / expired / decided invite — never write against a dead link.
        if ($application === null || ! $application->isInviteLive()) {
            abort(404);
        }

        // Spam mitigation on top of the route rate limit: a filled honeypot or an
        // impossibly-fast submit is discarded SILENTLY (identical thank-you response),
        // so an automated submitter never learns its rows aren't landing.
        if (ApplicationSpamGuard::looksAutomated($request)) {
            return $this->submittedRedirect($token);
        }

        $data = $request->validated();

        $payload = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'],
            'address' => $data['address'] ?? null,
            'document_type' => $data['document_type'],
            'document_number' => $data['document_number'],
            'is_therapeutic' => (bool) ($data['is_therapeutic'] ?? false),
            // Sponsor by name OR number (prompt 97): resolve best-effort, and KEEP the raw text the applicant
            // typed so the reviewer can find them even when it doesn't match a record exactly.
            'avalador_ref' => $data['avalador_ref'] ?? null,
            'avalador_member_id' => $this->resolveAvalador($application, $data['avalador_ref'] ?? null),
            // Empty ("Prefiero no indicarlo") arrives as null (ConvertEmptyStringsToNull) ⇒ no declaration.
            'declared_monthly_cg' => isset($data['declared_monthly_g'])
                ? Weight::fromGrams($data['declared_monthly_g'])->centigrams
                : null,
            'consents' => ['membership', 'data_processing'],
            // The consent-text version AND the locale the applicant ACTUALLY saw, captured now — RecordMemberConsent
            // stamps THESE at approval, so neither the recorded version nor the language can become a later revision
            // they never read (prompts 97 + 153). The locale is the resolved request locale (SetLocale → ResolveLocale),
            // i.e. the one the form's consent block was rendered in.
            'consent_version' => (string) Settings::get('consent_text_version', '1.0'),
            'consent_locale' => app()->getLocale(),
        ];

        // Optional identity photo (prompt 157): encrypt it onto the private disk NOW (never the public disk,
        // never an unsigned path), and carry only the path on the payload — ApproveApplication points the new
        // member at it. Skipped is fine; the form never requires it. A rejected/abandoned application (one never
        // approved) is anonymised and this photo deleted by `applications:prune-retention` past
        // application_retention_days — the retention the ID scan raised, now actually enforced (not prompt 142's
        // sweep, which only prunes member-import CSVs — that claim was wrong; a security audit caught it).
        if ($request->hasFile('photo')) {
            $payload['photo_path'] = DocumentVault::storeUpload($request->file('photo'), 'member-photos');
        }

        // Still PENDING — it now carries the applicant's details and enters the review queue.
        $application->update(['payload' => $payload, 'submitted_at' => now()]);

        return $this->submittedRedirect($token);
    }

    /** The post-submit redirect — byte-identical for a genuine submit and a silently-dropped bot. */
    private function submittedRedirect(string $token): RedirectResponse
    {
        return redirect()
            ->route('socio.application', ['token' => $token])
            ->with('status', __('¡Gracias! Hemos recibido tu solicitud. La asociación la revisará y, si se aprueba, recibirás por correo tu tarjeta de socio/a con un código QR para identificarte. La revisión puede tardar unos días.'));
    }

    /** A pending invite matching the token hash, or null. Revoke/expiry are checked by the caller. */
    private function find(string $token): ?MemberApplication
    {
        return MemberApplication::query()->withoutGlobalScopes()
            ->where('invite_token_hash', hash('sha256', $token))
            ->where('status', ApplicationStatus::PENDING)
            ->first();
    }

    /**
     * Best-effort: match a sponsor by member NUMBER or by NAME within the invite's organisation (prompt 97 —
     * a prospect usually knows the name, not the number). An ambiguous name match is left unresolved for the
     * reviewer (the raw text is kept on the payload) rather than guessing the wrong socio.
     */
    private function resolveAvalador(MemberApplication $application, ?string $ref): ?string
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }

        $base = fn () => Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $application->organisation_id)
            ->where('status', MemberStatus::ACTIVE->value);

        $byNumber = $base()->where('member_no', $ref)->value('id');
        if ($byNumber !== null) {
            return $byNumber;
        }

        // A NAME match, only when it is unambiguous (exactly one active socio). Matched in PHP so the
        // full-name comparison is portable across SQLite (dev) and MySQL (prod).
        $needle = mb_strtolower($ref);
        $byName = $base()->get(['id', 'first_name', 'last_name'])
            ->filter(fn (Member $m): bool => mb_strtolower(trim($m->first_name.' '.$m->last_name)) === $needle)
            ->pluck('id');

        return $byName->count() === 1 ? (string) $byName->first() : null;
    }
}
