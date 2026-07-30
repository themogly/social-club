<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Http\Requests\SubmitApplicationRequest;
use App\Models\Member;
use App\Models\MemberApplication;
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
        $application = $this->resolve($token);

        return view('socio.application', [
            'token' => $token,
            'application' => $application,
            'payload' => $application->payload ?? [],
        ]);
    }

    public function store(SubmitApplicationRequest $request, string $token): RedirectResponse
    {
        $application = $this->resolve($token);
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
        $application->update(['payload' => $payload]);

        return redirect()
            ->route('socio.application', ['token' => $token])
            ->with('status', __('¡Gracias! Hemos recibido tu solicitud. La asociación la revisará y te avisará por correo.'));
    }

    /** A valid, unconsumed invite: PENDING status + matching token hash. 404 otherwise. */
    private function resolve(string $token): MemberApplication
    {
        return MemberApplication::query()->withoutGlobalScopes()
            ->where('invite_token_hash', hash('sha256', $token))
            ->where('status', ApplicationStatus::PENDING)
            ->firstOr(fn () => abort(404));
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
