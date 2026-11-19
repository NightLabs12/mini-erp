<?php

declare(strict_types=1);

use App\Domain\Core\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Menyiapkan 6 role dan seluruh permission sesuai PermissionRegistry.
 */
function seedRbac(): void
{
    test()->seed(RolePermissionSeeder::class);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(string $role, array $attributes = []): User
{
    /** @var User $user */
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Login lewat API dan mengembalikan header Authorization siap pakai.
 *
 * @return array<string, string>
 */
function bearerFor(User $user): array
{
    $token = $user->createToken('test')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}
