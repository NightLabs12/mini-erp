<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Purchasing\Models\SupplierPayment;

final class SupplierPaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('purchase.supplier-payment.view');
    }

    public function view(User $actor, SupplierPayment $payment): bool
    {
        return $actor->can('purchase.supplier-payment.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('purchase.supplier-payment.create');
    }

    public function update(User $actor, SupplierPayment $payment): bool
    {
        return $actor->can('purchase.supplier-payment.update');
    }

    public function delete(User $actor, SupplierPayment $payment): bool
    {
        return $actor->can('purchase.supplier-payment.delete');
    }

    public function approve(User $actor, SupplierPayment $payment): bool
    {
        return $actor->can('purchase.supplier-payment.approve');
    }

    public function disapprove(User $actor, SupplierPayment $payment): bool
    {
        return $actor->can('purchase.supplier-payment.disapprove');
    }
}
