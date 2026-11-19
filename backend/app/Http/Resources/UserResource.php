<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Core\Models\User;
use Illuminate\Http\Request;

final class UserResource extends ApiResource
{
    private readonly User $user;

    public function __construct(User $user)
    {
        parent::__construct($user);

        $this->user = $user;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->user->getKey(),
            'name' => $this->user->name,
            'email' => $this->user->email,
            'roles' => $this->user->roleNames(),
            'permissions' => $this->user->permissionNames(),
            'created_at' => $this->user->created_at?->toIso8601String(),
            'updated_at' => $this->user->updated_at?->toIso8601String(),
            'deleted_at' => $this->user->deleted_at?->toIso8601String(),
        ];
    }
}
