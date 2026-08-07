<?php

namespace App\Http\Controllers;

use App\Actions\Members\SubmitApplication;
use App\Enums\ApplicationStatus;
use App\Http\Requests\SubmitApplicationRequest;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Support\ApplicationSpamGuard;
use App\Support\Mrz\MrzParser;
use App\Support\MrzPrefill;
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

        // Everything the submission MEANS — payload assembly, the avalador match, grams to centigrams, the
        // consent version and locale, the rate-limited vault uploads, the MRZ read rate — belongs to the
        // Action (code-style audit). The controller resolves, guards and redirects.
        (new SubmitApplication)->handle(
            $application,
            $request->validated(),
            ['photo' => $request->file('photo'), 'document_scan' => $request->file('document_scan')],
            $token,
            $request->ip(),
        );

        return $this->submittedRedirect($token);
    }

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
}
