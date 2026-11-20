<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Core\Models\User;

final class AccountingPeriodPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('accounting.period.view');
    }

    public function view(User $actor, AccountingPeriod $period): bool
    {
        return $actor->can('accounting.period.view');
    }

    public function close(User $actor, AccountingPeriod $period): bool
    {
        return $actor->can('accounting.period.close');
    }

    /**
     * Reopen sengaja lebih ketat dari permission: hanya role admin.
     */
    public function reopen(User $actor, AccountingPeriod $period): bool
    {
        return $actor->hasRole('admin') && $actor->can('accounting.period.reopen');
    }
}
