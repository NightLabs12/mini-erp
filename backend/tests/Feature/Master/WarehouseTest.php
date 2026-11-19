<?php

declare(strict_types=1);

use App\Domain\Master\Models\Warehouse;

beforeEach(function (): void {
    seedRbac();
    $this->headers = bearerFor(userWithRole('admin'));
});

it('melakukan CRUD gudang', function (): void {
    $id = $this->withHeaders($this->headers)
        ->postJson('/api/v1/warehouses', [
            'code' => 'gd-utm',
            'name' => 'Gudang Utama',
            'address' => 'Jl. Industri Raya 12',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'GD-UTM')
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/warehouses/{$id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/warehouses?filter[is_active]=false')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/warehouses/{$id}")
        ->assertNoContent();

    expect(Warehouse::query()->whereKey($id)->exists())->toBeFalse();
});

it('menolak kode gudang duplikat', function (): void {
    Warehouse::query()->create(['code' => 'GD-1', 'name' => 'Gudang 1']);

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/warehouses', ['code' => 'GD-1', 'name' => 'Gudang Lain'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['code']]);
});

it('mencatat audit dan menyediakan riwayatnya', function (): void {
    $id = $this->withHeaders($this->headers)
        ->postJson('/api/v1/warehouses', ['code' => 'GD-AUD', 'name' => 'Gudang Audit'])
        ->json('data.id');

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/warehouses/{$id}/audit")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event', 'created')
        ->assertJsonPath('data.0.new_values.code', 'GD-AUD');
});
