<?php

declare(strict_types=1);

it('mendokumentasikan endpoint Fase 1 dan Fase 2 pada OpenAPI', function (): void {
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
        '/v1/units',
        '/v1/units/{unit}',
        '/v1/units/{unit}/audit',
        '/v1/item-categories',
        '/v1/item-categories/{itemCategory}',
        '/v1/items',
        '/v1/items/{item}',
        '/v1/items/{item}/unit-conversions',
        '/v1/items/{item}/unit-conversions/{conversion}',
        '/v1/warehouses',
        '/v1/suppliers',
        '/v1/customers',
        '/v1/selling-prices',
        '/v1/selling-prices/lookup',
    ]);

    expect((string) $response->json('servers.0.url'))->toEndWith('/api');
});
