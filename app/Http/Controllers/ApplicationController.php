<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Http\Requests\SubmitApplicationRequest;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Support\ApplicationSpamGuard;
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
            'avalador_member_id' => $this->resolveAvalador($application, $data['avalador_member_no'] ?? null),
            'declared_monthly_cg' => isset($data['declared_monthly_g'])
                ? Weight::fromGrams($data['declared_monthly_g'])->centigrams
                : null,
            'consents' => ['membership', 'data_processing'],
        ];

        // Still PENDING — it now carries the applicant's details and enters the review queue.
        $application->update(['payload' => $payload, 'submitted_at' => now()]);

        return $this->submittedRedirect($token);
    }

    /** The post-submit redirect — byte-identical for a genuine submit and a silently-dropped bot. */
    private function submittedRedirect(string $token): RedirectResponse
    {
        return redirect()
            ->route('socio.application', ['token' => $token])
            ->with('status', __('¡Gracias! Hemos recibido tu solicitud. La asociación la revisará y te avisará por correo.'));
    }

    /** A pending invite matching the token hash, or null. Revoke/expiry are checked by the caller. */
    private function find(string $token): ?MemberApplication
    {
        return MemberApplication::query()->withoutGlobalScopes()
            ->where('invite_token_hash', hash('sha256', $token))
            ->where('status', ApplicationStatus::PENDING)
            ->first();
    }

    /** Best-effort: match a sponsor by member number within the invite's organisation. */
    private function resolveAvalador(MemberApplication $application, ?string $memberNo): ?string
    {
        if ($memberNo === null || trim($memberNo) === '') {
            return null;
        }

        return Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $application->organisation_id)
            ->where('member_no', trim($memberNo))
            ->where('status', MemberStatus::ACTIVE->value)
            ->value('id');
    }
}
