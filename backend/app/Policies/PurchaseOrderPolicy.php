<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Purchasing\Models\PurchaseOrder;

final class PurchaseOrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('purchase.purchase-order.view');
    }

    public function view(User $actor, PurchaseOrder $order): bool
    {
        return $actor->can('purchase.purchase-order.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('purchase.purchase-order.create');
    }

    public function update(User $actor, PurchaseOrder $order): bool
    {
        return $actor->can('purchase.purchase-order.update');
    }

    public function delete(User $actor, PurchaseOrder $order): bool
    {
        return $actor->can('purchase.purchase-order.delete');
    }

    public function approve(User $actor, PurchaseOrder $order): bool
    {
        return $actor->can('purchase.purchase-order.approve');
    }

    public function disapprove(User $actor, PurchaseOrder $order): bool
    {
        return $actor->can('purchase.purchase-order.disapprove');
    }

    public function close(User $actor, PurchaseOrder $order): bool
    {
        return $actor->can('purchase.purchase-order.close');
    }

    public function print(User $actor, PurchaseOrder $order): bool
    {
        return $actor->can('purchase.purchase-order.print');
    }
}
