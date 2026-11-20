<?php

declare(strict_types=1);

use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\SellingPrice;

beforeEach(function (): void {
    seedRbac();
    $this->headers = bearerFor(userWithRole('admin'));

    $this->pcs = masterUnit('PCS', 'Pcs');
    $this->dus = masterUnit('DUS', 'Dus');
    $this->item = masterItem($this->pcs, ['sku' => 'KAOS-POLOS', 'name' => 'Kaos Polos']);
    addConversion($this->item, $this->dus, '144');

    $this->customer = Customer::query()->create(['code' => 'CUS-1', 'name' => 'Toko Satu']);
});

it('mengembalikan harga umum bila tidak ada harga khusus customer', function (): void {
    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '15000',
    ]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/selling-prices/lookup?item_id='.$this->item->getKey().'&unit_id='.$this->pcs->getKey())
        ->assertOk()
        ->assertJsonPath('data.price', '15000.0000')
        ->assertJsonPath('data.customer_id', null)
        ->assertJsonPath('data.unit_price_base', '15000.0000');
});

it('memprioritaskan harga khusus customer yang valid', function (): void {
    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '15000',
    ]);

    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'customer_id' => $this->customer->getKey(),
        'price' => '14000',
    ]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/selling-prices/lookup?item_id='.$this->item->getKey()
            .'&unit_id='.$this->pcs->getKey()
            .'&customer_id='.$this->customer->getKey())
        ->assertOk()
        ->assertJsonPath('data.price', '14000.0000')
        ->assertJsonPath('data.customer_id', $this->customer->getKey());
});

it('jatuh kembali ke harga umum bila harga khusus customer kedaluwarsa', function (): void {
    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '15000',
    ]);

    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'customer_id' => $this->customer->getKey(),
        'price' => '9000',
        'valid_from' => now()->subYear()->toDateString(),
        'valid_to' => now()->subMonth()->toDateString(),
    ]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/selling-prices/lookup?item_id='.$this->item->getKey()
            .'&unit_id='.$this->pcs->getKey()
            .'&customer_id='.$this->customer->getKey())
        ->assertOk()
        ->assertJsonPath('data.price', '15000.0000');
});

it('tidak memakai harga yang belum berlaku pada tanggal yang diminta', function (): void {
    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '17000',
        'valid_from' => '2026-12-01',
    ]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/selling-prices/lookup?item_id='.$this->item->getKey()
            .'&unit_id='.$this->pcs->getKey()
            .'&date=2026-11-20')
        ->assertStatus(404)
        ->assertJsonPath('code', 'SELLING_PRICE_NOT_FOUND');

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/selling-prices/lookup?item_id='.$this->item->getKey()
            .'&unit_id='.$this->pcs->getKey()
            .'&date=2026-12-05')
        ->assertOk()
        ->assertJsonPath('data.price', '17000.0000');
});

it('menghitung harga per satuan base untuk satuan non-base', function (): void {
    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->dus->getKey(),
        'price' => '1440000',
    ]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/selling-prices/lookup?item_id='.$this->item->getKey().'&unit_id='.$this->dus->getKey())
        ->assertOk()
        ->assertJsonPath('data.price', '1440000.0000')
        ->assertJsonPath('data.unit_price_base', '10000.0000');
});

it('memilih valid_from terbaru bila ada beberapa harga umum', function (): void {
    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '12000',
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-05-31',
    ]);

    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '13500',
        'valid_from' => '2026-06-01',
    ]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/selling-prices/lookup?item_id='.$this->item->getKey().'&unit_id='.$this->pcs->getKey())
        ->assertOk()
        ->assertJsonPath('data.price', '13500.0000');
});

it('memvalidasi payload harga jual', function (): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/selling-prices', [
            'item_id' => $this->item->getKey(),
            'unit_id' => $this->pcs->getKey(),
            'price' => 0,
            'valid_from' => '2026-06-01',
            'valid_to' => '2026-05-01',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['price', 'valid_to']]);
});

it('menolak rentang harga yang tumpang tindih', function (): void {
    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '15000',
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-06-30',
    ]);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/selling-prices', [
            'item_id' => $this->item->getKey(),
            'unit_id' => $this->pcs->getKey(),
            'price' => '16000',
            'valid_from' => '2026-06-01',
            'valid_to' => '2026-12-31',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PRICE_PERIOD_OVERLAP');
});

it('memakai batas tanggal inklusif saat memeriksa tumpang tindih', function (): void {
    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '15000',
        'valid_from' => '2026-06-01',
        'valid_to' => '2026-06-30',
    ]);

    // Mulai sehari setelah batas akhir: tidak tumpang tindih.
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/selling-prices', [
            'item_id' => $this->item->getKey(),
            'unit_id' => $this->pcs->getKey(),
            'price' => '16000',
            'valid_from' => '2026-07-01',
            'valid_to' => '2026-07-31',
        ])
        ->assertCreated();

    // Mulai tepat pada batas akhir: inklusif, sehingga tumpang tindih.
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/selling-prices', [
            'item_id' => $this->item->getKey(),
            'unit_id' => $this->pcs->getKey(),
            'price' => '17000',
            'valid_from' => '2026-06-30',
            'valid_to' => '2026-07-15',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PRICE_PERIOD_OVERLAP');
});

it('memperlakukan valid_to null sebagai berlaku selamanya', function (): void {
    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '15000',
        'valid_from' => '2026-01-01',
    ]);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/selling-prices/lookup?item_id='.$this->item->getKey()
            .'&unit_id='.$this->pcs->getKey()
            .'&date=2030-12-31')
        ->assertOk()
        ->assertJsonPath('data.price', '15000.0000');

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/selling-prices', [
            'item_id' => $this->item->getKey(),
            'unit_id' => $this->pcs->getKey(),
            'price' => '17000',
            'valid_from' => '2027-01-01',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PRICE_PERIOD_OVERLAP');
});

it('menolak perubahan harga yang membuat rentang tumpang tindih', function (): void {
    $first = SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '15000',
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-03-31',
    ]);

    $second = SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->pcs->getKey(),
        'price' => '16000',
        'valid_from' => '2026-04-01',
        'valid_to' => '2026-06-30',
    ]);

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/selling-prices/{$second->getKey()}", [
            'valid_from' => '2026-03-01',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PRICE_PERIOD_OVERLAP');

    expect($first->fresh()->valid_from->toDateString())->toBe('2026-01-01');
});

it('melakukan CRUD harga jual', function (): void {
    $id = $this->withHeaders($this->headers)
        ->postJson('/api/v1/selling-prices', [
            'item_id' => $this->item->getKey(),
            'unit_id' => $this->pcs->getKey(),
            'customer_id' => $this->customer->getKey(),
            'price' => 14500,
        ])
        ->assertCreated()
        ->assertJsonPath('data.price', '14500.0000')
        ->json('data.id');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/selling-prices/{$id}", ['price' => 14750])
        ->assertOk()
        ->assertJsonPath('data.price', '14750.0000');

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/selling-prices?filter[customer_id]='.$this->customer->getKey())
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/selling-prices/{$id}")
        ->assertNoContent();
});
