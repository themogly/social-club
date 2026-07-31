<?php

namespace App\Policies;

use App\Models\MemberDocument;
use App\Models\User;
use App\Support\ActiveScope;

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
        // Permission AND object-ownership (audit S2): the document's member must be in the
        // actor's active organisation — a valid signed URL for a different org still 403s.
        // member is org-scoped: a cross-org document resolves member to null → denied. The
        // explicit org comparison is belt-and-braces if the scope isn't applied for any reason.
        return $user->can('member.documents.view')
            && $model->member?->organisation_id === app(ActiveScope::class)->organisationId();
    }
}
