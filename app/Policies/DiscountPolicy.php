<?php

namespace App\Policies;

use App\Models\User;

/** Discount templates are org-wide catalogue — gated on `discounts.manage`. */
class DiscountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('discounts.manage');
    }

    public function view(User $user): bool
    {
        return $user->can('discounts.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('discounts.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('discounts.manage');
    }

    public function delete(User $user): bool
    {
        return $user->can('discounts.manage');
    }

    public function restore(User $user): bool
    {
        return $user->can('discounts.manage');
    }
}
