<?php

declare(strict_types=1);

use App\Domain\Core\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    seedRbac();
});

it('menolak token user yang dinonaktifkan dengan 403', function (): void {
    $user = userWithRole('viewer');
    $headers = bearerFor($user);

    $user->update(['is_active' => false]);

    $this->withHeaders($headers)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(403)
        ->assertJsonPath('code', 'USER_INACTIVE');
});

it('menolak token user yang sudah di-soft-delete dengan 401', function (): void {
    $user = userWithRole('viewer');
    $headers = bearerFor($user);

    $user->delete();

    $this->withHeaders($headers)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

it('menolak login user nonaktif setelah password terbukti benar', function (): void {
    $user = userWithRole('viewer', ['email' => 'nonaktif@example.test', 'password' => 'rahasia123']);
    $user->update(['is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nonaktif@example.test',
        'password' => 'rahasia123',
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'USER_INACTIVE');

    // Password salah tetap dijawab sebagai kredensial tidak valid, bukan
    // membocorkan status akun.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'nonaktif@example.test',
        'password' => 'salah-sekali',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('mencabut token lain saat password diubah oleh pemiliknya', function (): void {
    $user = userWithRole('admin', ['email' => 'gantipass@example.test', 'password' => 'password-lama']);

    $first = $user->createToken('laptop')->plainTextToken;
    $second = $user->createToken('ponsel')->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$first])
        ->putJson("/api/v1/users/{$user->getKey()}", ['password' => 'password-baru'])
        ->assertOk();

    expect($user->tokens()->count())->toBe(1);

    $this->app['auth']->forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$second])
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);

    $this->app['auth']->forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$first])
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    expect(Hash::check('password-baru', $user->fresh()?->password ?? ''))->toBeTrue();
});

it('mencabut seluruh token saat admin mengganti password user lain', function (): void {
    $admin = userWithRole('admin');
    $target = userWithRole('viewer', ['email' => 'target@example.test']);
    $targetToken = $target->createToken('milik-target')->plainTextToken;

    $this->withHeaders(bearerFor($admin))
        ->putJson("/api/v1/users/{$target->getKey()}", ['password' => 'password-baru'])
        ->assertOk();

    expect($target->tokens()->count())->toBe(0);

    $this->app['auth']->forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$targetToken])
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

it('mencabut token saat user dinonaktifkan', function (): void {
    $admin = userWithRole('admin');
    $target = userWithRole('viewer', ['email' => 'nonaktifkan@example.test']);
    $target->createToken('aktif');

    $this->withHeaders(bearerFor($admin))
        ->putJson("/api/v1/users/{$target->getKey()}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($target->tokens()->count())->toBe(0);
});

it('menolak menonaktifkan admin aktif terakhir', function (): void {
    $admin = userWithRole('admin');

    $this->withHeaders(bearerFor($admin))
        ->putJson("/api/v1/users/{$admin->getKey()}", ['is_active' => false])
        ->assertStatus(409)
        ->assertJsonPath('code', 'LAST_ADMIN');
});

it('user yang dibuat nonaktif tidak dapat login', function (): void {
    $admin = userWithRole('admin');

    $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/users', [
            'name' => 'Belum Aktif',
            'email' => 'belum@example.test',
            'password' => 'rahasia123',
            'is_active' => false,
            'roles' => ['viewer'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_active', false);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'belum@example.test',
        'password' => 'rahasia123',
    ])->assertStatus(403)->assertJsonPath('code', 'USER_INACTIVE');
});

it('memakai default is_active true saat field tidak dikirim', function (): void {
    expect(User::factory()->create()->is_active)->toBeTrue();
});
