<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Master\Models\Warehouse;

final class WarehousePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('master.warehouse.view');
    }

    public function view(User $actor, Warehouse $warehouse): bool
    {
        return $actor->can('master.warehouse.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('master.warehouse.create');
    }

    public function update(User $actor, Warehouse $warehouse): bool
    {
        return $actor->can('master.warehouse.update');
    }

    public function delete(User $actor, Warehouse $warehouse): bool
    {
        return $actor->can('master.warehouse.delete');
    }
}
