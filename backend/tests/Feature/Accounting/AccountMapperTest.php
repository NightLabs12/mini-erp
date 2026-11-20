<?php

declare(strict_types=1);

use App\Domain\Accounting\Services\AccountMapper;
use App\Domain\Master\Models\ItemCategory;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);
    $this->mapper = app(AccountMapper::class);
    $this->pcs = masterUnit('PCS', 'Pcs');
});

it('mengambil akun default dari account_settings', function (): void {
    $category = ItemCategory::query()->create([
        'code' => 'TRD',
        'name' => 'Trading',
        'item_type' => 'trading',
    ]);
    $item = masterItem($this->pcs, ['category_id' => $category->getKey()]);

    expect($this->mapper->inventoryFor($item)->code)->toBe('1-1400')
        ->and($this->mapper->cogsFor($item)->code)->toBe('5-1000')
        ->and($this->mapper->salesFor($item)->code)->toBe('4-1000');
});

it('memakai akun persediaan sesuai jenis item', function (): void {
    $category = ItemCategory::query()->create([
        'code' => 'RAW',
        'name' => 'Bahan Baku',
        'item_type' => 'raw_material',
    ]);
    $item = masterItem($this->pcs, ['category_id' => $category->getKey()]);

    expect($this->mapper->inventoryFor($item)->code)->toBe('1-1410');
});

it('mengutamakan override akun pada kategori item', function (): void {
    $custom = accountByCode('1-1600');
    $category = ItemCategory::query()->create([
        'code' => 'OVR',
        'name' => 'Override',
        'item_type' => 'trading',
        'inventory_account_id' => $custom->getKey(),
    ]);
    $item = masterItem($this->pcs, ['category_id' => $category->getKey()]);

    expect($this->mapper->inventoryFor($item)->code)->toBe('1-1600');
});
