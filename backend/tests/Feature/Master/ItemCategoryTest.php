<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Master\Models\ItemCategory;

beforeEach(function (): void {
    seedRbac();
    $this->headers = bearerFor(userWithRole('admin'));
});

it('membuat kategori dengan jenis item yang valid', function (): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/item-categories', [
            'code' => 'kain',
            'name' => 'Kain Katun',
            'item_type' => 'raw_material',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'KAIN')
        ->assertJsonPath('data.item_type', 'raw_material')
        ->assertJsonPath('data.inventory_account_id', null);
});

it('menolak jenis item di luar daftar', function (): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/item-categories', [
            'code' => 'X',
            'name' => 'Tidak Valid',
            'item_type' => 'jasa',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['item_type']]);
});

it('menolak menghapus kategori yang masih punya item', function (): void {
    $pcs = masterUnit('PCS');
    $item = masterItem($pcs);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/item-categories/{$item->category_id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'MASTER_IN_USE')
        ->assertJsonPath('errors.references.0', 'items');
});

it('menyimpan akun default per kategori', function (): void {
    $inventory = Account::query()->create([
        'code' => '1-9000',
        'name' => 'Persediaan Uji',
        'type' => 'asset',
        'normal_balance' => 'D',
    ]);
    $cogs = Account::query()->create([
        'code' => '5-9000',
        'name' => 'HPP Uji',
        'type' => 'cogs',
        'normal_balance' => 'D',
    ]);
    $sales = Account::query()->create([
        'code' => '4-9000',
        'name' => 'Penjualan Uji',
        'type' => 'revenue',
        'normal_balance' => 'C',
    ]);

    $id = $this->withHeaders($this->headers)
        ->postJson('/api/v1/item-categories', [
            'code' => 'KAOS',
            'name' => 'Kaos Jadi',
            'item_type' => 'finished_good',
            'inventory_account_id' => $inventory->getKey(),
            'cogs_account_id' => $cogs->getKey(),
            'sales_account_id' => $sales->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.inventory_account_id', $inventory->getKey())
        ->json('data.id');

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/item-categories/{$id}/audit")
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    expect(ItemCategory::query()->find($id)?->cogs_account_id)->toBe($cogs->getKey());
});

it('memfilter daftar berdasarkan jenis item', function (): void {
    ItemCategory::query()->create(['code' => 'A', 'name' => 'A', 'item_type' => 'trading']);
    ItemCategory::query()->create(['code' => 'B', 'name' => 'B', 'item_type' => 'raw_material']);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/item-categories?filter[item_type]=raw_material')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', 'B');
});
