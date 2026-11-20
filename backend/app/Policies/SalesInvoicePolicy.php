<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Sales\Models\SalesInvoice;

final class SalesInvoicePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('sales.sales-invoice.view');
    }

    public function view(User $actor, SalesInvoice $invoice): bool
    {
        return $actor->can('sales.sales-invoice.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sales.sales-invoice.create');
    }

    public function update(User $actor, SalesInvoice $invoice): bool
    {
        return $actor->can('sales.sales-invoice.update');
    }

    public function delete(User $actor, SalesInvoice $invoice): bool
    {
        return $actor->can('sales.sales-invoice.delete');
    }

    public function approve(User $actor, SalesInvoice $invoice): bool
    {
        return $actor->can('sales.sales-invoice.approve');
    }

    public function disapprove(User $actor, SalesInvoice $invoice): bool
    {
        return $actor->can('sales.sales-invoice.disapprove');
    }

    public function print(User $actor, SalesInvoice $invoice): bool
    {
        return $actor->can('sales.sales-invoice.print');
    }
}
