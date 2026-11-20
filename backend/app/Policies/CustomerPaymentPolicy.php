<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Sales\Models\CustomerPayment;

final class CustomerPaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('sales.customer-payment.view');
    }

    public function view(User $actor, CustomerPayment $payment): bool
    {
        return $actor->can('sales.customer-payment.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sales.customer-payment.create');
    }

    public function update(User $actor, CustomerPayment $payment): bool
    {
        return $actor->can('sales.customer-payment.update');
    }

    public function delete(User $actor, CustomerPayment $payment): bool
    {
        return $actor->can('sales.customer-payment.delete');
    }

    public function approve(User $actor, CustomerPayment $payment): bool
    {
        return $actor->can('sales.customer-payment.approve');
    }

    public function disapprove(User $actor, CustomerPayment $payment): bool
    {
        return $actor->can('sales.customer-payment.disapprove');
    }
}
