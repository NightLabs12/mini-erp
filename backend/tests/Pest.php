<?php

declare(strict_types=1);

use App\Domain\Core\Models\User;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\ItemUnitConversion;
use App\Domain\Master\Models\Unit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Menyiapkan 6 role dan seluruh permission sesuai PermissionRegistry.
 */
function seedRbac(): void
{
    test()->seed(RolePermissionSeeder::class);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(string $role, array $attributes = []): User
{
    /** @var User $user */
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Login lewat API dan mengembalikan header Authorization siap pakai.
 *
 * @return array<string, string>
 */
function bearerFor(User $user): array
{
    $token = $user->createToken('test')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * Satuan master dengan kode unik; aman dipanggil berulang dalam satu test.
 */
function masterUnit(string $code, ?string $name = null, int $decimalPlaces = 0): Unit
{
    /** @var Unit $unit */
    $unit = Unit::query()->updateOrCreate(
        ['code' => strtoupper($code)],
        ['name' => $name ?? ucfirst(strtolower($code)), 'decimal_places' => $decimalPlaces],
    );

    return $unit;
}

/**
 * Item lengkap dengan kategori dan satuan base.
 *
 * @param  array<string, mixed>  $attributes
 */
function masterItem(Unit $baseUnit, array $attributes = []): Item
{
    $category = ItemCategory::query()->firstOrCreate(
        ['code' => 'KAT-'.$baseUnit->code],
        ['name' => 'Kategori '.$baseUnit->name, 'item_type' => 'trading'],
    );

    /** @var Item $item */
    $item = Item::query()->create([
        'sku' => 'SKU-'.$baseUnit->code,
        'name' => 'Item '.$baseUnit->name,
        'category_id' => $category->getKey(),
        'base_unit_id' => $baseUnit->getKey(),
        'min_stock' => '0',
        'is_active' => true,
        ...$attributes,
    ]);

    return $item;
}

/**
 * Menambahkan konversi satuan ke item.
 */
function addConversion(Item $item, Unit $unit, string $factor, bool $purchase = false, bool $sales = false): ItemUnitConversion
{
    /** @var ItemUnitConversion $conversion */
    $conversion = $item->conversions()->create([
        'unit_id' => $unit->getKey(),
        'factor_to_base' => $factor,
        'is_purchase_default' => $purchase,
        'is_sales_default' => $sales,
    ]);

    return $conversion;
}
