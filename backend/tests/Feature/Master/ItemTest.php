<?php

declare(strict_types=1);

use App\Domain\Master\Models\Item;

beforeEach(function (): void {
    seedRbac();
    $this->headers = bearerFor(userWithRole('admin'));

    $this->pcs = masterUnit('PCS', 'Pcs');
    $this->pack = masterUnit('PACK', 'Pack');
    $this->dus = masterUnit('DUS', 'Dus');
    $this->category = App\Domain\Master\Models\ItemCategory::query()->create([
        'code' => 'KAOS',
        'name' => 'Kaos Jadi',
        'item_type' => 'finished_good',
    ]);
});

it('membuat item beserta konversi satuannya', function (): void {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/items', [
            'sku' => 'kaos-polos',
            'name' => 'Kaos Polos',
            'category_id' => $this->category->getKey(),
            'base_unit_id' => $this->pcs->getKey(),
            'min_stock' => 24,
            'conversions' => [
                ['unit_id' => $this->pack->getKey(), 'factor_to_base' => 12, 'is_sales_default' => true],
                ['unit_id' => $this->dus->getKey(), 'factor_to_base' => 144, 'is_purchase_default' => true],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.sku', 'KAOS-POLOS')
        ->assertJsonPath('data.category.code', 'KAOS')
        ->assertJsonPath('data.base_unit.code', 'PCS')
        ->assertJsonCount(2, 'data.conversions');

    $item = Item::query()->where('sku', 'KAOS-POLOS')->firstOrFail();

    expect($item->conversions()->count())->toBe(2)
        ->and((string) $item->conversions()->where('unit_id', $this->dus->getKey())->value('factor_to_base'))
        ->toBe('144.0000');
});

it('menolak satuan base yang tidak ada', function (): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/items', [
            'sku' => 'X-1',
            'name' => 'Item X',
            'category_id' => $this->category->getKey(),
            'base_unit_id' => 99999,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['base_unit_id']]);
});

it('menolak satuan base yang ditulis ulang sebagai konversi', function (): void {
    $response = $this->withHeaders($this->headers)
        ->postJson('/api/v1/items', [
            'sku' => 'X-2',
            'name' => 'Item X',
            'category_id' => $this->category->getKey(),
            'base_unit_id' => $this->pcs->getKey(),
            'conversions' => [
                ['unit_id' => $this->pcs->getKey(), 'factor_to_base' => 1],
            ],
        ]);

    $response->assertStatus(422);

    // Laravel menyimpan error field bersarang sebagai kunci datar.
    expect($response->json('errors'))->toHaveKey('conversions.0.unit_id');
});

it('menolak satuan konversi duplikat dan faktor nol', function (): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/items', [
            'sku' => 'X-3',
            'name' => 'Item X',
            'category_id' => $this->category->getKey(),
            'base_unit_id' => $this->pcs->getKey(),
            'conversions' => [
                ['unit_id' => $this->pack->getKey(), 'factor_to_base' => 12],
                ['unit_id' => $this->pack->getKey(), 'factor_to_base' => 12],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['conversions.1.unit_id']]);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/items', [
            'sku' => 'X-4',
            'name' => 'Item X',
            'category_id' => $this->category->getKey(),
            'base_unit_id' => $this->pcs->getKey(),
            'conversions' => [
                ['unit_id' => $this->pack->getKey(), 'factor_to_base' => 0],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['conversions.0.factor_to_base']]);
});

it('menolak SKU duplikat tetapi mengizinkan pemakaian ulang setelah dihapus', function (): void {
    $item = masterItem($this->pcs, ['sku' => 'DUP-1', 'category_id' => $this->category->getKey()]);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/items', [
            'sku' => 'DUP-1',
            'name' => 'Duplikat',
            'category_id' => $this->category->getKey(),
            'base_unit_id' => $this->pcs->getKey(),
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['sku']]);

    $item->delete();

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/items', [
            'sku' => 'DUP-1',
            'name' => 'Setelah Hapus',
            'category_id' => $this->category->getKey(),
            'base_unit_id' => $this->pcs->getKey(),
        ])
        ->assertCreated();
});

it('menyelaraskan konversi saat item diperbarui', function (): void {
    $item = masterItem($this->pcs, ['category_id' => $this->category->getKey()]);
    addConversion($item, $this->pack, '12');
    addConversion($item, $this->dus, '144');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/items/{$item->getKey()}", [
            'conversions' => [
                ['unit_id' => $this->pack->getKey(), 'factor_to_base' => 10],
                ['unit_id' => $this->dus->getKey(), 'factor_to_base' => 120],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'data.conversions');

    expect((string) $item->conversions()->where('unit_id', $this->pack->getKey())->value('factor_to_base'))
        ->toBe('10.0000');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/items/{$item->getKey()}", [
            'conversions' => [
                ['unit_id' => $this->pack->getKey(), 'factor_to_base' => 12],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'data.conversions');

    expect($item->conversions()->count())->toBe(1);
});

it('menolak menghapus item yang punya harga jual', function (): void {
    $item = masterItem($this->pcs, ['category_id' => $this->category->getKey()]);

    $item->sellingPrices()->create([
        'unit_id' => $this->pcs->getKey(),
        'price' => '15000',
    ]);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/items/{$item->getKey()}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'MASTER_IN_USE');
});

it('memfilter dan mencari item', function (): void {
    $other = App\Domain\Master\Models\ItemCategory::query()->create([
        'code' => 'LAIN',
        'name' => 'Lain',
        'item_type' => 'trading',
    ]);

    masterItem($this->pcs, ['sku' => 'AAA-1', 'name' => 'Kaos Putih', 'category_id' => $this->category->getKey()]);
    masterItem($this->pcs, ['sku' => 'BBB-2', 'name' => 'Kain Katun', 'category_id' => $other->getKey()]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/items?filter[category_id]='.$other->getKey())
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.sku', 'BBB-2');

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/items?search=kaos')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Kaos Putih');
});

it('mencatat audit item saat dibuat dan diubah', function (): void {
    $item = masterItem($this->pcs, ['category_id' => $this->category->getKey()]);

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/items/{$item->getKey()}", ['name' => 'Nama Baru'])
        ->assertOk();

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/items/{$item->getKey()}/audit")
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.event', 'updated')
        ->assertJsonPath('data.0.diff.name.new', 'Nama Baru');
});
