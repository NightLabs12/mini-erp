<?php

declare(strict_types=1);

it('mendokumentasikan endpoint Fase 1 pada OpenAPI', function (): void {
    $response = $this->getJson('/docs/api.json');

    $response->assertOk();

    /** @var array<string, mixed> $paths */
    $paths = $response->json('paths');

    // Scramble memisahkan prefix /api ke servers[].url, sehingga path dimulai dari /v1.
    expect($paths)->toHaveKeys([
        '/v1/auth/login',
        '/v1/auth/logout',
        '/v1/auth/me',
        '/v1/users',
        '/v1/users/{user}',
        '/v1/users/{user}/audit',
        '/v1/audit-logs',
    ]);

    expect((string) $response->json('servers.0.url'))->toEndWith('/api');
});
