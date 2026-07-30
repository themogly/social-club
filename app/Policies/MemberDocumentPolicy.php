<?php

namespace App\Policies;

use App\Models\MemberDocument;
use App\Models\User;

/**
 * Generated member documents are immutable artifacts on the private disk. They are
 * VIEWED under `member.documents.view` (every open is access-logged through
 * IssueDocumentUrl) and are never edited or deleted through the panel — a correction is
 * a new generated version, never a mutation. There is deliberately no create/update/
 * delete ability here; generation happens through the member "Generar documento" action.
 */
class MemberDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('member.documents.view');
    }

    public function view(User $user, MemberDocument $model): bool
    {
        return $user->can('member.documents.view');
    }
}
