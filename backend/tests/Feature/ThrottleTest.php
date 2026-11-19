<?php

declare(strict_types=1);

it('membatasi request API sesuai konfigurasi', function (): void {
    config(['app.api_rate_limit' => 2]);

    $this->getJson('/api/v1/health')->assertOk();
    $this->getJson('/api/v1/health')->assertOk();

    $this->getJson('/api/v1/health')
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED');
});
