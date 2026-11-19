<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Core\Support\PermissionRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Menyiapkan permission dan role dari PermissionRegistry.
 *
 * Memakai upsert massal agar seeder tetap cepat saat dipanggil berulang
 * (misalnya pada setiap test), bukan satu query per permission.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $now = now();
        $guard = 'web';
        $permissions = PermissionRegistry::permissions();

        Permission::query()->upsert(
            array_map(static fn (string $name): array => [
                'name' => $name,
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ], $permissions),
            ['name', 'guard_name'],
            ['updated_at'],
        );

        $ids = Permission::query()->pluck('id', 'name');

        foreach (PermissionRegistry::ROLES as $role) {
            $model = Role::findOrCreate($role, $guard);

            $slugs = PermissionRegistry::permissionsForRole($role);

            $model->permissions()->sync(
                $ids->only($slugs)->values()->all(),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
