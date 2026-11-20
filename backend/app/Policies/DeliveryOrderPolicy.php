<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Sales\Models\DeliveryOrder;

final class DeliveryOrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('sales.delivery-order.view');
    }

    public function view(User $actor, DeliveryOrder $delivery): bool
    {
        return $actor->can('sales.delivery-order.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sales.delivery-order.create');
    }

    public function update(User $actor, DeliveryOrder $delivery): bool
    {
        return $actor->can('sales.delivery-order.update');
    }

    public function delete(User $actor, DeliveryOrder $delivery): bool
    {
        return $actor->can('sales.delivery-order.delete');
    }

    public function approve(User $actor, DeliveryOrder $delivery): bool
    {
        return $actor->can('sales.delivery-order.approve');
    }

    public function disapprove(User $actor, DeliveryOrder $delivery): bool
    {
        return $actor->can('sales.delivery-order.disapprove');
    }

    public function print(User $actor, DeliveryOrder $delivery): bool
    {
        return $actor->can('sales.delivery-order.print');
    }
}
