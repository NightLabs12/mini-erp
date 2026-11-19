<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;

/**
 * Aksi pada resource user dikendalikan permission core.user.*.
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('core.user.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can('core.user.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('core.user.create');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can('core.user.update');
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->can('core.user.delete') && $actor->getKey() !== $target->getKey();
    }
}
