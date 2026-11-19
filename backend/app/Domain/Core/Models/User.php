<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Core\Traits\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return list<string> */
    protected function auditExcludedAttributes(): array
    {
        return ['password', 'remember_token', 'email_verified_at', 'updated_at'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @return array<int, string> */
    public function roleNames(): array
    {
        $this->loadMissing('roles');

        return $this->roles
            ->map(static fn (Model $role): string => (string) $role->getAttribute('name'))
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function permissionNames(): array
    {
        // getAllPermissions() membaca roles.permissions dan permissions langsung.
        $this->loadMissing(['roles.permissions', 'permissions']);

        return $this->getAllPermissions()
            ->map(static fn (Permission $permission): string => (string) $permission->name)
            ->sort()
            ->values()
            ->all();
    }
}
