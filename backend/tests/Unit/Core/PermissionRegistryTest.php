<?php

declare(strict_types=1);

use App\Domain\Core\Support\PermissionRegistry;

it('membentuk slug {modul}.{resource}.{aksi} untuk seluruh modul', function (): void {
    $permissions = PermissionRegistry::permissions();

    expect($permissions)->not->toBeEmpty();

    foreach ($permissions as $permission) {
        expect($permission)->toMatch('/^[a-z]+(\.[a-z0-9-]+){2}$/');
    }

    expect($permissions)
        ->toContain('core.user.create')
        ->toContain('purchase.purchase-order.approve')
        ->toContain('sales.sales-invoice.approve')
        ->toContain('inventory.stock-opname.approve')
        ->toContain('accounting.period.close')
        ->toContain('report.general-ledger.export');
});

it('memberi admin seluruh permission', function (): void {
    expect(PermissionRegistry::permissionsForRole('admin'))->toBe(PermissionRegistry::permissions());
});

it('membatasi viewer pada aksi view saja', function (): void {
    foreach (PermissionRegistry::permissionsForRole('viewer') as $permission) {
        expect($permission)->toEndWith('.view');
    }
});

it('memisahkan modul antar role operasional', function (): void {
    $purchasing = PermissionRegistry::permissionsForRole('purchasing');
    $sales = PermissionRegistry::permissionsForRole('sales');
    $warehouse = PermissionRegistry::permissionsForRole('warehouse');

    expect($purchasing)->toContain('purchase.purchase-order.approve')
        ->and($purchasing)->not->toContain('sales.sales-invoice.approve')
        ->and($sales)->toContain('sales.sales-invoice.approve')
        ->and($sales)->not->toContain('purchase.purchase-order.approve')
        ->and($warehouse)->toContain('inventory.stock-opname.approve')
        ->and($warehouse)->not->toContain('accounting.period.close');
});
