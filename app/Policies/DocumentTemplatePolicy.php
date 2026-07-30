<?php

namespace App\Policies;

use App\Models\DocumentTemplate;
use App\Models\User;

/**
 * Document templates are administered under `documents.generate` (whoever may generate
 * a member document owns the templates those documents render from). Editing never
 * rewrites a version in place — the resource persists a NEW version row on save — so
 * already-generated documents (which froze their own snapshot) are never altered.
 * Server-side authorisation only.
 */
class DocumentTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('documents.generate');
    }

    public function view(User $user, DocumentTemplate $model): bool
    {
        return $user->can('documents.generate');
    }

    public function create(User $user): bool
    {
        return $user->can('documents.generate');
    }

    public function update(User $user, DocumentTemplate $model): bool
    {
        return $user->can('documents.generate');
    }

    public function delete(User $user, DocumentTemplate $model): bool
    {
        return $user->can('documents.generate');
    }
}
