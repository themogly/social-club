<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;
use App\Support\ActiveScope;

/** Suppliers are managed alongside purchases (purchases.manage), org-scoped. */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchases.manage');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->can('purchases.manage')
            && $supplier->organisation_id === app(ActiveScope::class)->organisationId();
    }

    public function create(User $user): bool
    {
        return $user->can('purchases.manage');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->view($user, $supplier);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->view($user, $supplier);
    }
}
