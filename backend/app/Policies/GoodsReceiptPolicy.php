<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Purchasing\Models\GoodsReceipt;

final class GoodsReceiptPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('purchase.goods-receipt.view');
    }

    public function view(User $actor, GoodsReceipt $receipt): bool
    {
        return $actor->can('purchase.goods-receipt.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('purchase.goods-receipt.create');
    }

    public function update(User $actor, GoodsReceipt $receipt): bool
    {
        return $actor->can('purchase.goods-receipt.update');
    }

    public function delete(User $actor, GoodsReceipt $receipt): bool
    {
        return $actor->can('purchase.goods-receipt.delete');
    }

    public function approve(User $actor, GoodsReceipt $receipt): bool
    {
        return $actor->can('purchase.goods-receipt.approve');
    }

    public function disapprove(User $actor, GoodsReceipt $receipt): bool
    {
        return $actor->can('purchase.goods-receipt.disapprove');
    }
}
