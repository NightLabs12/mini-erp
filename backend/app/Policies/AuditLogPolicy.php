<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Core\Models\User;

final class AuditLogPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('core.audit.view');
    }
}
