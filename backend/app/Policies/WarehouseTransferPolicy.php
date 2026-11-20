<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Inventory\Models\WarehouseTransfer;

final class WarehouseTransferPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('inventory.warehouse-transfer.view');
    }

    public function view(User $actor, WarehouseTransfer $transfer): bool
    {
        return $actor->can('inventory.warehouse-transfer.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('inventory.warehouse-transfer.create');
    }

    public function update(User $actor, WarehouseTransfer $transfer): bool
    {
        return $actor->can('inventory.warehouse-transfer.update');
    }

    public function delete(User $actor, WarehouseTransfer $transfer): bool
    {
        return $actor->can('inventory.warehouse-transfer.delete');
    }

    public function approve(User $actor, WarehouseTransfer $transfer): bool
    {
        return $actor->can('inventory.warehouse-transfer.approve');
    }

    public function disapprove(User $actor, WarehouseTransfer $transfer): bool
    {
        return $actor->can('inventory.warehouse-transfer.disapprove');
    }
}
