<?php

namespace App\Policies;

use App\Models\Member;
use App\Models\User;
use App\Support\ActiveScope;

/**
 * The member directory is org-wide. Viewing is gated on `members.view`, creation
 * on `members.create` and every mutation (edit, soft delete, restore) on
 * `members.edit`. Members soft-delete only — there is deliberately no force-delete
 * ability, so attribution and history are never destroyed. Server-side — the
 * Filament resource authorises through this policy, never by hiding a button.
 */
class MemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('members.view');
    }

    public function view(User $user, Member $model): bool
    {
        return $user->can('members.view');
    }

    public function create(User $user): bool
    {
        return $user->can('members.create');
    }

    public function update(User $user, Member $model): bool
    {
        return $user->can('members.edit');
    }

    public function delete(User $user, Member $model): bool
    {
        return $user->can('members.edit');
    }

    public function restore(User $user, Member $model): bool
    {
        return $user->can('members.edit');
    }

    /**
     * View a member's PHOTO through the encrypted, access-logged streaming endpoint (prompt 113). Identity
     * data seen at the counter to recognise the member, so it is `members.view` (STAFF hold it) + org
     * ownership — NOT the owner-only `member.documents.view` that gates the ID scan and medical certificate.
     * Every view is still logged, whatever the permission.
     */
    public function viewPhoto(User $user, Member $model): bool
    {
        return $user->can('members.view')
            && $model->organisation_id === app(ActiveScope::class)->organisationId();
    }

    /**
     * Capture (or replace) a member's identity PHOTO at the counter (prompt 157). This happens at the door and
     * the POS, performed by the STAFF who run them — so it is gated on operating one of those surfaces
     * (`checkin.manage` OR `pos.use`, both STAFF-held), NOT on the manager-only `members.edit`. Gating it on
     * `members.edit` would mean the very people at the counter could not take the photo, defeating the point.
     * It is still a mutation of identity data, so it stays org-scoped and every write is audited.
     */
    public function capturePhoto(User $user, Member $model): bool
    {
        return ($user->can('checkin.manage') || $user->can('pos.use'))
            && $model->organisation_id === app(ActiveScope::class)->organisationId();
    }

    /**
     * Set a per-member limit override (prompt 81) — the daily/monthly gram caps that sit at the TOP of the
     * precedence chain (member → tier → location → org, ResolveMemberLimits). A distinct, higher authority
     * than a plain member edit: `member.limits.set` (manager+), never bundled into `members.edit`. The
     * member is org-scoped by the global scope, so cross-org access is already impossible.
     */
    public function setLimits(User $user, Member $model): bool
    {
        return $user->can('member.limits.set');
    }
}
