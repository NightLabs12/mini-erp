<?php

declare(strict_types=1);

beforeEach(function (): void {
    seedRbac();
});

it('menolak akses tanpa permission dengan 403', function (): void {
    $purchasing = userWithRole('purchasing');

    $this->withHeaders(bearerFor($purchasing))
        ->getJson('/api/v1/users')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('mengizinkan viewer membaca user tetapi tidak mengubahnya', function (): void {
    $viewer = userWithRole('viewer');
    userWithRole('sales', ['email' => 'sales@example.test']);

    $this->withHeaders(bearerFor($viewer))
        ->getJson('/api/v1/users')
        ->assertOk();

    $this->withHeaders(bearerFor($viewer))
        ->postJson('/api/v1/users', [
            'name' => 'Penyusup',
            'email' => 'penyusup@example.test',
            'password' => 'rahasia123',
        ])
        ->assertStatus(403);
});

it('menolak audit log bagi role tanpa core.audit.view', function (): void {
    $purchasing = userWithRole('purchasing');

    $this->withHeaders(bearerFor($purchasing))
        ->getJson('/api/v1/audit-logs')
        ->assertStatus(403);
});

it('mengizinkan audit log bagi admin', function (): void {
    $admin = userWithRole('admin');

    $this->withHeaders(bearerFor($admin))
        ->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta', 'links']);
});

it('admin dapat melihat daftar user beserta permission-nya', function (): void {
    $admin = userWithRole('admin');
    userWithRole('sales', ['email' => 'sales@example.test']);

    $this->withHeaders(bearerFor($admin))
        ->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'roles', 'permissions']]]);
});

it('membatasi setiap role pada modulnya sendiri', function (): void {
    $warehouse = userWithRole('warehouse');

    $this->withHeaders(bearerFor($warehouse))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data', function (array $data): bool {
            $permissions = $data['permissions'];

            return in_array('inventory.stock-opname.approve', $permissions, true)
                && ! in_array('sales.sales-invoice.approve', $permissions, true)
                && ! in_array('accounting.period.close', $permissions, true);
        });
});
