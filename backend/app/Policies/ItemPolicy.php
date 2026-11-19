<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Master\Models\Item;

final class ItemPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('master.item.view');
    }

    public function view(User $actor, Item $item): bool
    {
        return $actor->can('master.item.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('master.item.create');
    }

    public function update(User $actor, Item $item): bool
    {
        return $actor->can('master.item.update');
    }

    public function delete(User $actor, Item $item): bool
    {
        return $actor->can('master.item.delete');
    }
}
