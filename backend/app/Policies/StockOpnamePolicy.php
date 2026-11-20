<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Inventory\Models\StockOpname;

final class StockOpnamePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('inventory.stock-opname.view');
    }

    public function view(User $actor, StockOpname $opname): bool
    {
        return $actor->can('inventory.stock-opname.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('inventory.stock-opname.create');
    }

    public function update(User $actor, StockOpname $opname): bool
    {
        return $actor->can('inventory.stock-opname.update');
    }

    public function delete(User $actor, StockOpname $opname): bool
    {
        return $actor->can('inventory.stock-opname.delete');
    }

    public function approve(User $actor, StockOpname $opname): bool
    {
        return $actor->can('inventory.stock-opname.approve');
    }

    public function disapprove(User $actor, StockOpname $opname): bool
    {
        return $actor->can('inventory.stock-opname.disapprove');
    }
}
