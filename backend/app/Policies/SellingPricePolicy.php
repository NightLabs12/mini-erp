<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Master\Models\SellingPrice;

final class SellingPricePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('master.selling-price.view');
    }

    public function view(User $actor, SellingPrice $sellingPrice): bool
    {
        return $actor->can('master.selling-price.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('master.selling-price.create');
    }

    public function update(User $actor, SellingPrice $sellingPrice): bool
    {
        return $actor->can('master.selling-price.update');
    }

    public function delete(User $actor, SellingPrice $sellingPrice): bool
    {
        return $actor->can('master.selling-price.delete');
    }
}
