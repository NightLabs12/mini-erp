<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;
use App\Domain\Master\Models\Unit;

final class UnitPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('master.unit.view');
    }

    public function view(User $actor, Unit $unit): bool
    {
        return $actor->can('master.unit.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('master.unit.create');
    }

    public function update(User $actor, Unit $unit): bool
    {
        return $actor->can('master.unit.update');
    }

    public function delete(User $actor, Unit $unit): bool
    {
        return $actor->can('master.unit.delete');
    }
}
