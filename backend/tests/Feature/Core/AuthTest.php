<?php

declare(strict_types=1);

beforeEach(function (): void {
    seedRbac();
});

it('login mengembalikan token, masa berlaku, dan daftar permission', function (): void {
    userWithRole('viewer', ['email' => 'viewer@example.test', 'password' => 'rahasia123']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'viewer@example.test',
        'password' => 'rahasia123',
        'device_name' => 'pest',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure([
            'data' => [
                'token',
                'token_type',
                'expires_at',
                'user' => ['id', 'name', 'email', 'roles', 'permissions'],
            ],
        ]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('data.user.permissions'))->toContain('core.user.view')
        ->and($response->json('data.user.roles'))->toBe(['viewer']);
});

it('menolak kredensial yang salah tanpa membocorkan apakah email terdaftar', function (): void {
    userWithRole('viewer', ['email' => 'viewer@example.test', 'password' => 'rahasia123']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'viewer@example.test',
        'password' => 'salah-sekali',
    ])->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'tidak-ada@example.test',
        'password' => 'rahasia123',
    ])->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('menolak login dengan payload tidak lengkap', function (): void {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['email', 'password']]);
});

it('mewajibkan token untuk endpoint terproteksi', function (): void {
    $this->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('me mengembalikan user beserta role dan permission', function (): void {
    $user = userWithRole('finance', ['email' => 'finance@example.test']);

    $this->withHeaders(bearerFor($user))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'finance@example.test')
        ->assertJsonPath('data.roles.0', 'finance')
        ->assertJsonPath('data', fn (array $data): bool => in_array('accounting.period.close', $data['permissions'], true));
});

it('logout mencabut token yang sedang dipakai', function (): void {
    $user = userWithRole('viewer');
    $headers = bearerFor($user);

    $this->withHeaders($headers)->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0);

    // Guard auth di-cache selama satu proses test; lupakan agar request
    // berikutnya benar-benar membaca ulang token dari database.
    $this->app['auth']->forgetGuards();

    $this->withHeaders($headers)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

it('membatasi percobaan login sesuai konfigurasi', function (): void {
    config(['app.login_rate_limit' => 3]);

    $payload = ['email' => 'bruteforce@example.test', 'password' => 'salah'];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', $payload)
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED');
});

it('token kedaluwarsa tidak dapat dipakai', function (): void {
    $user = userWithRole('viewer');
    $token = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});
