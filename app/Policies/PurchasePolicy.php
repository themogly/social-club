<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;
use App\Support\ActiveScope;

/** Purchases (incl. cannabis intake links) are managed under purchases.manage, org-scoped. */
class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchases.manage');
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $user->can('purchases.manage')
            && $purchase->organisation_id === app(ActiveScope::class)->organisationId();
    }

    public function create(User $user): bool
    {
        return $user->can('purchases.manage');
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return $this->view($user, $purchase);
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return $this->view($user, $purchase);
    }
}
