<?php

namespace App\Policies;

use App\Models\Article;
use App\Models\User;

/**
 * Bar/food/merch articles are per-location. Every action is gated on
 * `articles.manage`. The global LocationScope on Article already prevents
 * cross-location access. Server-side — the Filament resource authorises through
 * this policy, never by hiding a button.
 */
class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('articles.manage');
    }

    public function view(User $user, Article $model): bool
    {
        return $user->can('articles.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('articles.manage');
    }

    public function update(User $user, Article $model): bool
    {
        return $user->can('articles.manage');
    }

    public function delete(User $user, Article $model): bool
    {
        return $user->can('articles.manage');
    }

    public function restore(User $user, Article $model): bool
    {
        return $user->can('articles.manage');
    }

    public function forceDelete(User $user, Article $model): bool
    {
        return $user->can('articles.manage');
    }
}
