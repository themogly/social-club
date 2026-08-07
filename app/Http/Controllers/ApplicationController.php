<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Http\Requests\SubmitApplicationRequest;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MrzFieldStat;
use App\Support\ApplicationSpamGuard;
use App\Support\DocumentVault;
use App\Support\Mrz\MrzParser;
use App\Support\MrzPrefill;
use App\Support\Settings;
use App\Support\Weight;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
    /** File-bearing submissions per IP per hour — bounds vault writes, not bandwidth (see store()). */
    private const UPLOADS_PER_HOUR = 5;

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
            // Prompt 179 — what the browser read, still awaiting confirmation. Empty is the ordinary case.
            'prefill' => MrzPrefill::get($token),
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
        // Rate limiting, and what it does and does not defend (prompt 178).
        //
        // The uploads are NOT a separate endpoint — they ride the same POST, so the route's `throttle:10,1`
        // and ApplicationSpamGuard already apply to them unchanged. What that does not bound is STORAGE: ten
        // submissions a minute, each up to the shared 12 MB ceiling, is ~120 MB/min of encrypted vault writes
        // per IP, sustained, on an unauthenticated route. So file-bearing submissions get their own, tighter
        // limit on top. It is honest about its scope — the bytes have already crossed the wire and been parsed
        // by PHP before this runs, so this bounds what reaches the DISK, not bandwidth; bandwidth is nginx's
        // `client_max_body_size` and PHP's `upload_max_filesize` (prompt 164 reconciled those three numbers).
        //
        // Five an hour is far above a genuine applicant (who uploads once, twice if they mis-picked a file)
        // and far below anything worth doing to a disk. Over the limit the application still SUBMITS — the
        // files are simply not stored, because an upload is optional and losing it must never cost someone
        // their application.
        $filesPresent = $request->hasFile('photo') || $request->hasFile('document_scan');
        $storageAllowed = ! $filesPresent || RateLimiter::attempt(
            'application-upload:'.$request->ip(),
            self::UPLOADS_PER_HOUR,
            fn () => true,
            3600,
        );

        if ($storageAllowed && $request->hasFile('photo')) {
            $payload['photo_path'] = DocumentVault::storeUpload($request->file('photo'), 'member-photos');
        }

        // Optional identity DOCUMENT (prompt 178 — 155's part B). Same vault, same private encrypted disk, same
        // `member-id-scans` directory the staff MemberForm already writes to, so an application's scan and a
        // member's scan are one kind of object with one serving path (signed, access-logged). A DIFFERENT
        // artefact from the photo above — a face is not a document — so it gets its own payload key and its own
        // member column, and the two are never merged. On approval the member points at THIS SAME FILE rather
        // than a copy; until then `applications:prune-retention` deletes it with the rest of the payload.
        if ($storageAllowed && $request->hasFile('document_scan')) {
            $payload['document_scan_path'] = DocumentVault::storeUpload($request->file('document_scan'), 'member-id-scans');
        }

        // Prompt 179 — the read rate, measured from real use: was each prefilled field corrected? Counts
        // only, recorded BEFORE the prefill is discarded, and the prefill is discarded here so the next
        // person handed this tablet cannot inherit it.
        $this->recordMrzCorrections($application, $payload, $token);

        // Still PENDING — it now carries the applicant's details and enters the review queue.
        $application->update(['payload' => $payload, 'submitted_at' => now()]);

        return $this->submittedRedirect($token);
    }

    /** The post-submit redirect — byte-identical for a genuine submit and a silently-dropped bot. */
    /**
     * Read an MRZ (prompt 179). The BROWSER did the OCR; this receives the resulting TEXT and parses it with
     * the one parser that already exists.
     *
     * The image never arrives here for the purpose of being read — that is the whole privacy argument, and
     * a test pins that this endpoint accepts a string and nothing else. The raw MRZ lives for the length of
     * this request: parsed, mapped, discarded. It is never persisted, never logged and never echoed back.
     *
     * A failed or invalid read is an ORDINARY outcome, not an error: the parser is correct-or-invalid, so a
     * garbled scan yields nothing and the applicant fills the form exactly as they do today. No warning, no
     * red state, no suggestion they did something wrong.
     */
    public function read(Request $request, string $token): RedirectResponse
    {
        $application = $this->find($token);

        if ($application === null || ! $application->isInviteLive()) {
            abort(404);
        }

        // Rate limited like any unauthenticated write, and bounded in size — an MRZ is at most three lines
        // of 44 characters, so anything larger is not an MRZ.
        $raw = (string) $request->input('mrz', '');

        if (mb_strlen($raw) > 200 || ! RateLimiter::attempt('application-mrz:'.$request->ip(), 20, fn () => true, 3600)) {
            return $this->backToForm($token);
        }

        $parsed = (new MrzParser)->parse($raw);

        // `valid` is the ICAO check-digit verdict. A broken digit means a mis-read, and a mis-read must
        // never prefill a document number — 128 built the parser correct-or-invalid for exactly this.
        if ($parsed === null || $parsed['valid'] !== true) {
            MrzPrefill::forget($token);

            return $this->backToForm($token);
        }

        MrzPrefill::remember($token, [
            'first_name' => $parsed['given_names'],
            'last_name' => $parsed['surname'],
            'document_number' => $parsed['document_number'],
            // The only nullable one: a TD1/TD3 date can fail to parse while the rest of the zone reads.
            'date_of_birth' => (string) ($parsed['birth_date'] ?? ''),
        ]);

        return $this->backToForm($token);
    }

    private function backToForm(string $token): RedirectResponse
    {
        // withInput() so a value the applicant already typed survives the round trip and the prefill fills
        // only blanks — prefill fills gaps, it does not correct people.
        return redirect()->route('socio.application', ['token' => $token])->withInput();
    }

    /**
     * Count, per field, how often a prefilled value was kept and how often it was corrected — which IS the
     * read rate, gathered on real documents with no corpus to assemble, hold or destroy.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordMrzCorrections(MemberApplication $application, array $payload, string $token): void
    {
        foreach (MrzPrefill::get($token) as $field => $read) {
            $submitted = $payload[$field] ?? null;

            MrzFieldStat::record(
                $application->organisation_id,
                $field,
                corrected: is_string($submitted) && trim($submitted) !== trim($read),
            );
        }

        MrzPrefill::forget($token);
    }

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
