<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Master\Models\Customer;

final class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('master.customer.view');
    }

    public function view(User $actor, Customer $customer): bool
    {
        return $actor->can('master.customer.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('master.customer.create');
    }

    public function update(User $actor, Customer $customer): bool
    {
        return $actor->can('master.customer.update');
    }

    public function delete(User $actor, Customer $customer): bool
    {
        return $actor->can('master.customer.delete');
    }
}
