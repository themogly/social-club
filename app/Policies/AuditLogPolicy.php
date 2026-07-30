<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * The audit trail is READ-ONLY and append-only: it is written by RecordAuditLog and can
 * never be edited or deleted (the model itself throws on update/delete). Viewing is gated
 * on `audit.view` (OWNER only). There is deliberately no create/update/delete ability —
 * their absence denies those abilities server-side, so the resource exposes no such route.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function view(User $user, AuditLog $model): bool
    {
        return $user->can('audit.view');
    }
}
