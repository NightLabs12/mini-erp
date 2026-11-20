<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Accounting\Models\Journal;
use App\Domain\Core\Models\User;

final class JournalPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('accounting.journal.view');
    }

    public function view(User $actor, Journal $journal): bool
    {
        return $actor->can('accounting.journal.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('accounting.journal.create');
    }

    public function update(User $actor, Journal $journal): bool
    {
        return $actor->can('accounting.journal.update');
    }

    public function delete(User $actor, Journal $journal): bool
    {
        return $actor->can('accounting.journal.delete');
    }

    public function approve(User $actor, Journal $journal): bool
    {
        return $actor->can('accounting.journal.approve');
    }

    public function reverse(User $actor, Journal $journal): bool
    {
        return $actor->can('accounting.journal.reverse');
    }
}
