<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Sales\Models\SalesOrder;

final class SalesOrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('sales.sales-order.view');
    }

    public function view(User $actor, SalesOrder $order): bool
    {
        return $actor->can('sales.sales-order.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sales.sales-order.create');
    }

    public function update(User $actor, SalesOrder $order): bool
    {
        return $actor->can('sales.sales-order.update');
    }

    public function delete(User $actor, SalesOrder $order): bool
    {
        return $actor->can('sales.sales-order.delete');
    }

    public function approve(User $actor, SalesOrder $order): bool
    {
        return $actor->can('sales.sales-order.approve');
    }

    public function disapprove(User $actor, SalesOrder $order): bool
    {
        return $actor->can('sales.sales-order.disapprove');
    }

    public function close(User $actor, SalesOrder $order): bool
    {
        return $actor->can('sales.sales-order.close');
    }

    public function print(User $actor, SalesOrder $order): bool
    {
        return $actor->can('sales.sales-order.print');
    }
}
