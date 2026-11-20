<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Purchasing\Models\PurchaseRequest;

final class PurchaseRequestPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('purchase.purchase-request.view');
    }

    public function view(User $actor, PurchaseRequest $request): bool
    {
        return $actor->can('purchase.purchase-request.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('purchase.purchase-request.create');
    }

    public function update(User $actor, PurchaseRequest $request): bool
    {
        return $actor->can('purchase.purchase-request.update');
    }

    public function delete(User $actor, PurchaseRequest $request): bool
    {
        return $actor->can('purchase.purchase-request.delete');
    }

    public function approve(User $actor, PurchaseRequest $request): bool
    {
        return $actor->can('purchase.purchase-request.approve');
    }

    public function disapprove(User $actor, PurchaseRequest $request): bool
    {
        return $actor->can('purchase.purchase-request.disapprove');
    }

    public function close(User $actor, PurchaseRequest $request): bool
    {
        return $actor->can('purchase.purchase-request.close');
    }
}
