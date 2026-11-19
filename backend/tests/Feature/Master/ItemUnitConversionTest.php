<?php

declare(strict_types=1);

beforeEach(function (): void {
    seedRbac();
    $this->headers = bearerFor(userWithRole('admin'));

    $this->pcs = masterUnit('PCS');
    $this->pack = masterUnit('PACK');
    $this->item = masterItem($this->pcs);
});

it('menambah konversi lewat endpoint item', function (): void {
    $this->withHeaders($this->headers)
        ->postJson("/api/v1/items/{$this->item->getKey()}/unit-conversions", [
            'unit_id' => $this->pack->getKey(),
            'factor_to_base' => 12,
            'is_purchase_default' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.unit.code', 'PACK')
        ->assertJsonPath('data.factor_to_base', '12.0000')
        ->assertJsonPath('data.is_purchase_default', true);
});

it('menolak satuan duplikat dan satuan base', function (): void {
    addConversion($this->item, $this->pack, '12');

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/items/{$this->item->getKey()}/unit-conversions", [
            'unit_id' => $this->pack->getKey(),
            'factor_to_base' => 10,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['unit_id']]);

    $this->withHeaders($this->headers)
        ->postJson("/api/v1/items/{$this->item->getKey()}/unit-conversions", [
            'unit_id' => $this->pcs->getKey(),
            'factor_to_base' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['unit_id']]);
});

it('mengubah dan menghapus konversi', function (): void {
    $conversion = addConversion($this->item, $this->pack, '12');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/items/{$this->item->getKey()}/unit-conversions/{$conversion->getKey()}", [
            'factor_to_base' => 10,
        ])
        ->assertOk()
        ->assertJsonPath('data.factor_to_base', '10.0000');

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/items/{$this->item->getKey()}/unit-conversions/{$conversion->getKey()}")
        ->assertNoContent();

    expect($this->item->conversions()->count())->toBe(0);
});

it('menolak konversi milik item lain', function (): void {
    $other = masterItem(masterUnit('METER'), ['sku' => 'LAIN-1']);
    $conversion = addConversion($other, $this->pack, '12');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/items/{$this->item->getKey()}/unit-conversions/{$conversion->getKey()}", [
            'factor_to_base' => 5,
        ])
        ->assertStatus(404)
        ->assertJsonPath('code', 'CONVERSION_NOT_FOUND');
});

it('menampilkan daftar konversi item', function (): void {
    addConversion($this->item, $this->pack, '12');

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/items/{$this->item->getKey()}/unit-conversions")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.unit.code', 'PACK');
});
