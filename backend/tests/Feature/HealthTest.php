<?php

declare(strict_types=1);

it('melaporkan status aplikasi dan koneksi database', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', 'ok')
        ->assertJsonStructure([
            'status',
            'app',
            'version',
            'environment',
            'time',
            'checks' => ['database'],
        ]);
});
