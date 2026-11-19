<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Master\Models\ItemCategory;

final class ItemCategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('master.item-category.view');
    }

    public function view(User $actor, ItemCategory $category): bool
    {
        return $actor->can('master.item-category.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('master.item-category.create');
    }

    public function update(User $actor, ItemCategory $category): bool
    {
        return $actor->can('master.item-category.update');
    }

    public function delete(User $actor, ItemCategory $category): bool
    {
        return $actor->can('master.item-category.delete');
    }
}
