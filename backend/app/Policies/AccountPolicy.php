<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\Account;
use App\Domain\Core\Models\User;

final class AccountPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('master.account.view');
    }

    public function view(User $actor, Account $account): bool
    {
        return $actor->can('master.account.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('master.account.create');
    }

    public function update(User $actor, Account $account): bool
    {
        return $actor->can('master.account.update');
    }

    public function delete(User $actor, Account $account): bool
    {
        return $actor->can('master.account.delete');
    }
}
