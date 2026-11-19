<?php

declare(strict_types=1);

beforeEach(function (): void {
    seedRbac();
    config(['app.login_rate_limit' => 3]);
});

it('mengunci per kombinasi email dan IP, bukan per email saja', function (): void {
    $payload = ['email' => 'kunci@example.test', 'password' => 'salah'];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.1'])
            ->postJson('/api/v1/auth/login', $payload)
            ->assertStatus(401);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.1'])
        ->postJson('/api/v1/auth/login', $payload)
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED');

    // IP lain tidak ikut terkunci untuk email yang sama.
    $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.2'])
        ->postJson('/api/v1/auth/login', $payload)
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('mengunci per email, sehingga email lain dari IP yang sama tetap dapat mencoba', function (): void {
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.1'])
            ->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'salah'])
            ->assertStatus(401);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.1'])
        ->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'salah'])
        ->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.1'])
        ->postJson('/api/v1/auth/login', ['email' => 'b@example.test', 'password' => 'salah'])
        ->assertStatus(401);
});

it('memberi pesan identik untuk email tak dikenal dan password salah', function (): void {
    userWithRole('viewer', ['email' => 'ada@example.test', 'password' => 'rahasia123']);

    $unknown = $this->withServerVariables(['REMOTE_ADDR' => '10.30.0.1'])
        ->postJson('/api/v1/auth/login', ['email' => 'tidak-ada@example.test', 'password' => 'rahasia123']);

    $wrongPassword = $this->withServerVariables(['REMOTE_ADDR' => '10.30.0.2'])
        ->postJson('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'salah-sekali']);

    expect($unknown->status())->toBe(401)
        ->and($wrongPassword->status())->toBe(401)
        ->and($unknown->json('message'))->toBe($wrongPassword->json('message'))
        ->and($unknown->json('code'))->toBe($wrongPassword->json('code'))
        ->and($unknown->json('errors'))->toBe($wrongPassword->json('errors'));
});

it('mengizinkan login setelah jendela rate limit lewat', function (): void {
    $payload = ['email' => 'jeda@example.test', 'password' => 'salah'];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', $payload)->assertStatus(429);

    $this->travel(2)->minutes();

    $this->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
});
