<?php

namespace App\Livewire\Counter\Concerns;

use App\Actions\Members\ApproveApplication;
use App\Actions\Members\FindDuplicateMembers;
use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Memberships\EnrolMembership;
use App\Exceptions\DuplicateMemberException;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MembershipTier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Alta at the counter (prompt 174) — sign somebody up without opening the panel.
 *
 * THE rule this lives or dies on: **it creates an APPLICATION, not a member.** The join form already exists
 * end to end — a tokenised route, `SubmitApplicationRequest`, two separate Article 9 consent ticks, the
 * encrypted ID upload (178), a spam guard — and `ApproveApplication` already does the age gate, the
 * duplicate search, the versioned consent capture naming the locale the applicant actually read, and the
 * member creation. What changes here is WHICH DEVICE the form is filled in on. Nothing else.
 *
 * So there is no second form, no second validator and no second consent capture in this file. The applicant
 * is sent to the REAL public form at the REAL public route with the REAL token — which is why a record made
 * at the counter is byte-comparable with one made from an emailed invite: it is not a parallel path, it is
 * the same path on a different screen.
 *
 * No new writer either. `IssueApplicationInvite` → `ApproveApplication` → `EnrolMembership` →
 * `RecordFeePayment` (the last via {@see CollectsMembershipFees}), each already audited, in that order.
 */
trait SignsUpMembers
{
    /** The application being reviewed after the tablet comes back, if any. */
    public ?string $altaApplicationId = null;

    /** Chosen tier at the review step. */
    public ?string $altaTierId = null;

    /** Email for the send-an-invitation path. */
    public string $altaInviteEmail = '';

    /** Set when approval hit a duplicate — the matches to show, and the decision to take. */
    public bool $altaDuplicateBlocked = false;

    /** Whether the Alta panel is expanded in the Socios tab. */
    public bool $altaOpen = false;

    public function toggleAlta(): void
    {
        $this->altaOpen = ! $this->altaOpen;
    }

    /**
     * Hand the tablet over: create the application, enter 173's handover mode, and send the tablet to the
     * public form.
     *
     * The redirect goes to the ordinary tokenised route on purpose. 173's guarantees still hold around it —
     * handover is session-backed, so the counter's chrome is absent from the DOM and the back button returns
     * to a PIN rather than to a counter screen — while the applicant gets the real form, with prompt 167's
     * language switcher, which is the same audience for the same reason.
     */
    public function handOverForAlta(): void
    {
        $operator = $this->requireOperatorForAlta();

        if ($operator === null) {
            return;
        }

        $application = $this->issueApplication($operator, email: null, reference: __('Alta en el mostrador'));

        if ($application === null) {
            return;
        }

        // 173's OWN entry point, not a second way in: it records the audit entry and signs the operator out
        // (which is what makes requireOperator() refuse every write while an applicant holds the device).
        // Last thing before the redirect, so the review steps below can only run after a fresh PIN.
        // The invite URL is recorded with the handover so EnforceCounterHandover can put the applicant back
        // on their form if they leave it, rather than bouncing them to a PIN pad they cannot use.
        $this->beginHandover($application->inviteUrl());

        $this->redirect($application->inviteUrl(), navigate: false);
    }

    /** Send an invitation instead — the same record and token shape, picked up on their next visit. */
    public function sendAltaInvitation(): void
    {
        $operator = $this->requireOperatorForAlta();

        if ($operator === null) {
            return;
        }

        $email = trim($this->altaInviteEmail);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash(__('Escribe un email válido para enviar la invitación.'), 'error');

            return;
        }

        if ($this->issueApplication($operator, email: $email, reference: null) === null) {
            return;
        }

        $this->altaInviteEmail = '';
        $this->flash(__('Invitación enviada. Aparecerá en la lista de solicitudes pendientes.'), 'success');
    }

    /**
     * Applications submitted at this sede and waiting to be finished.
     *
     * @return Collection<int, MemberApplication>
     */
    public function pendingAltaApplications(): Collection
    {
        if ($this->locationId === null || ! $this->userCan('applications.review')) {
            return collect();
        }

        return MemberApplication::query()->withoutGlobalScopes()
            ->where('location_id', $this->locationId)
            ->awaitingReview()   // the same scope the hub's alert counts (prompt 207)
            ->latest('submitted_at')
            ->limit(10)
            ->get();
    }

    public function reviewAltaApplication(string $applicationId): void
    {
        $this->altaApplicationId = $applicationId;
        $this->altaTierId = null;
        $this->altaDuplicateBlocked = false;
    }

    public function cancelAltaReview(): void
    {
        $this->reset(['altaApplicationId', 'altaTierId', 'altaDuplicateBlocked']);
    }

    /**
     * Approve the application and enrol the membership. Payment is NOT part of this — see below.
     *
     * @param  bool  $allowDuplicate  an explicit, deliberate override, never a default
     */
    public function approveAlta(bool $allowDuplicate = false): void
    {
        $operator = $this->requireOperatorForAlta();
        $application = $this->altaApplication();
        $location = $this->altaLocation();
        $tier = $this->altaTier();

        if ($operator === null || $application === null || $location === null) {
            return;
        }

        if (! $this->userCan('applications.review')) {
            $this->flash(__('No tienes permiso para aprobar solicitudes.'), 'error');

            return;
        }

        if ($tier === null) {
            $this->flash(__('Elige una cuota antes de aprobar.'), 'error');

            return;
        }

        try {
            $member = (new ApproveApplication)->handle($application, $operator->id, $allowDuplicate);
        } catch (DuplicateMemberException $e) {
            // Surfaced as a DECISION, never as a default. The matches are re-resolved read-only for display
            // because the exception carries them only inside its message.
            $this->altaDuplicateBlocked = true;
            $this->flash($e->getMessage(), 'warning');

            return;
        } catch (RuntimeException $e) {
            // Underage, or a payload missing a required name. Both are ordinary things that happen with a
            // person standing at the counter, so they get the action's own readable sentence — never a stack
            // trace — and the application stays PENDING so a responsable can decide what to do with it.
            $this->flash($e->getMessage(), 'error');

            return;
        }

        (new EnrolMembership)->handle($member, $location, $tier);

        // Approval and payment are DELIBERATELY not one transaction. If the fee cannot be taken — no cash,
        // a card machine that will not talk — the member still exists and owes it, which is an ordinary
        // state this product already represents and the counter already surfaces. Rolling back an admission
        // over a payment failure would be worse.
        $this->feeMemberId = $member->id;
        $this->reset(['altaApplicationId', 'altaTierId', 'altaDuplicateBlocked']);
        $this->altaOpen = false;

        $this->flash(__('Socio dado de alta. Cobra la cuota cuando quieras.'), 'success');
    }

    /** @return Collection<int, MembershipTier> */
    public function altaTiers(): Collection
    {
        return MembershipTier::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->altaLocation()?->organisation_id)
            ->orderBy('name')->get();
    }

    public function altaApplication(): ?MemberApplication
    {
        if ($this->altaApplicationId === null) {
            return null;
        }

        return MemberApplication::query()->withoutGlobalScopes()->find($this->altaApplicationId);
    }

    /**
     * The matches that blocked approval — read-only, resolved for display only.
     *
     * @return Collection<int, Member>
     */
    public function altaDuplicateMatches(): Collection
    {
        $payload = $this->altaApplication()?->payload;

        return is_array($payload) ? (new FindDuplicateMembers)->handle($payload) : collect();
    }

    private function altaTier(): ?MembershipTier
    {
        return $this->altaTierId !== null
            ? MembershipTier::query()->withoutGlobalScopes()->find($this->altaTierId)
            : null;
    }

    private function altaLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    /**
     * The sede comes from the counter's resolved location, never from the client, and the actor is the
     * PIN-identified operator rather than the device session user.
     */
    private function issueApplication(User $operator, ?string $email, ?string $reference): ?MemberApplication
    {
        try {
            return (new IssueApplicationInvite)->handle($operator, $this->locationId, $email, $reference);
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');

            return null;
        }
    }

    private function requireOperatorForAlta(): ?User
    {
        if (! $this->requireOperator()) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
