<?php

declare(strict_types=1);

use App\Domain\Core\Models\User;

beforeEach(function (): void {
    seedRbac();
});

it('menolak perubahan user saat DEMO_MODE aktif', function (): void {
    config(['app.demo_mode' => true]);

    $admin = userWithRole('admin');
    $target = userWithRole('viewer', ['email' => 'demo-target@example.test']);

    $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/users', [
            'name' => 'Tidak Boleh',
            'email' => 'tidak-boleh@example.test',
            'password' => 'rahasia123',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'DEMO_MODE_READONLY');

    $this->withHeaders(bearerFor($admin))
        ->putJson("/api/v1/users/{$target->getKey()}", ['name' => 'Diubah'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'DEMO_MODE_READONLY');

    $this->withHeaders(bearerFor($admin))
        ->deleteJson("/api/v1/users/{$target->getKey()}")
        ->assertStatus(403)
        ->assertJsonPath('code', 'DEMO_MODE_READONLY');

    expect(User::query()->where('email', 'tidak-boleh@example.test')->exists())->toBeFalse()
        ->and($target->fresh()?->name)->not->toBe('Diubah');
});

it('tetap meminta autentikasi lebih dulu di mode demo', function (): void {
    config(['app.demo_mode' => true]);

    $this->postJson('/api/v1/users', [
        'name' => 'Tanpa Token',
        'email' => 'tanpa-token@example.test',
        'password' => 'rahasia123',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('tetap mengizinkan baca dan login saat DEMO_MODE aktif', function (): void {
    config(['app.demo_mode' => true]);

    $admin = userWithRole('admin', ['email' => 'demo-admin@example.test', 'password' => 'rahasia123']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'demo-admin@example.test',
        'password' => 'rahasia123',
    ])->assertOk();

    $this->withHeaders(bearerFor($admin))
        ->getJson('/api/v1/users')
        ->assertOk();

    $this->withHeaders(bearerFor($admin))
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    // Logout bukan operasi data kritis, jadi tetap boleh.
    $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/auth/logout')
        ->assertOk();
});

it('tidak membatasi apa pun saat DEMO_MODE mati', function (): void {
    config(['app.demo_mode' => false]);

    $admin = userWithRole('admin');

    $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/users', [
            'name' => 'Boleh Dibuat',
            'email' => 'boleh@example.test',
            'password' => 'rahasia123',
            'roles' => ['viewer'],
        ])
        ->assertCreated();
});

it('perintah demo:reset berjalan di luar jalur HTTP sehingga tidak terblokir', function (): void {
    config(['app.demo_mode' => true]);

    $this->artisan('list')
        ->expectsOutputToContain('demo:reset')
        ->assertSuccessful();
});
