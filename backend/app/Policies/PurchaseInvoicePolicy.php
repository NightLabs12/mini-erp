<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Purchasing\Models\PurchaseInvoice;

final class PurchaseInvoicePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('purchase.purchase-invoice.view');
    }

    public function view(User $actor, PurchaseInvoice $invoice): bool
    {
        return $actor->can('purchase.purchase-invoice.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('purchase.purchase-invoice.create');
    }

    public function update(User $actor, PurchaseInvoice $invoice): bool
    {
        return $actor->can('purchase.purchase-invoice.update');
    }

    public function delete(User $actor, PurchaseInvoice $invoice): bool
    {
        return $actor->can('purchase.purchase-invoice.delete');
    }

    public function approve(User $actor, PurchaseInvoice $invoice): bool
    {
        return $actor->can('purchase.purchase-invoice.approve');
    }

    public function disapprove(User $actor, PurchaseInvoice $invoice): bool
    {
        return $actor->can('purchase.purchase-invoice.disapprove');
    }
}
