<?php

declare(strict_types=1);

beforeEach(function (): void {
    seedRbac();
});

it('menolak pembuatan master oleh role yang hanya punya akses baca', function (): void {
    $finance = userWithRole('finance');

    $this->withHeaders(bearerFor($finance))
        ->getJson('/api/v1/items')
        ->assertOk();

    $this->withHeaders(bearerFor($finance))
        ->postJson('/api/v1/units', ['code' => 'X', 'name' => 'X'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('menolak gudang membuat supplier', function (): void {
    $warehouse = userWithRole('warehouse');

    $this->withHeaders(bearerFor($warehouse))
        ->getJson('/api/v1/suppliers')
        ->assertOk();

    $this->withHeaders(bearerFor($warehouse))
        ->postJson('/api/v1/suppliers', ['code' => 'SUP-X', 'name' => 'Supplier X'])
        ->assertStatus(403);
});

it('mengizinkan viewer membaca semua master tetapi tidak mengubah', function (): void {
    $viewer = userWithRole('viewer');

    foreach (['units', 'item-categories', 'items', 'warehouses', 'suppliers', 'customers', 'selling-prices'] as $resource) {
        $this->withHeaders(bearerFor($viewer))
            ->getJson("/api/v1/{$resource}")
            ->assertOk();
    }

    $this->withHeaders(bearerFor($viewer))
        ->postJson('/api/v1/customers', ['code' => 'CUS-X', 'name' => 'Customer X'])
        ->assertStatus(403);
});

it('menolak akses tanpa token', function (): void {
    $this->getJson('/api/v1/items')
        ->assertStatus(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('tetap mengizinkan transaksi master saat mode demo, tetapi memblokir user', function (): void {
    config(['app.demo_mode' => true]);

    $admin = userWithRole('admin');

    $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/units', ['code' => 'DEMO', 'name' => 'Demo'])
        ->assertCreated();

    $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/users', [
            'name' => 'Diblokir',
            'email' => 'diblokir@example.test',
            'password' => 'rahasia123',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'DEMO_MODE_READONLY');
});
