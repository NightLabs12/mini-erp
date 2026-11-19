<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Master\Models\Supplier;

final class SupplierPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('master.supplier.view');
    }

    public function view(User $actor, Supplier $supplier): bool
    {
        return $actor->can('master.supplier.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('master.supplier.create');
    }

    public function update(User $actor, Supplier $supplier): bool
    {
        return $actor->can('master.supplier.update');
    }

    public function delete(User $actor, Supplier $supplier): bool
    {
        return $actor->can('master.supplier.delete');
    }
}
