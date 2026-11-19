<?php

declare(strict_types=1);

use App\Domain\Master\Models\Unit;

beforeEach(function (): void {
    seedRbac();
    $this->admin = userWithRole('admin');
    $this->headers = bearerFor($this->admin);
});

it('membuat, membaca, mengubah, dan menghapus satuan', function (): void {
    $created = $this->withHeaders($this->headers)
        ->postJson('/api/v1/units', ['code' => 'pcs', 'name' => 'Pcs'])
        ->assertCreated()
        ->assertJsonPath('data.code', 'PCS')
        ->assertJsonPath('data.name', 'Pcs');

    $id = $created->json('data.id');

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/units/{$id}")
        ->assertOk()
        ->assertJsonPath('data.code', 'PCS');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/units/{$id}", ['name' => 'Piece'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Piece');

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/units/{$id}")
        ->assertNoContent();

    expect(Unit::query()->whereKey($id)->exists())->toBeFalse()
        ->and(Unit::withTrashed()->whereKey($id)->exists())->toBeTrue();
});

it('menolak kode satuan duplikat', function (): void {
    masterUnit('PCS');

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/units', ['code' => 'pcs', 'name' => 'Pcs Lagi'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['code']]);
});

it('mengizinkan kode dipakai ulang setelah satuan dihapus', function (): void {
    $unit = masterUnit('PCS');
    $unit->delete();

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/units', ['code' => 'PCS', 'name' => 'Pcs Baru'])
        ->assertCreated();
});

it('menolak menghapus satuan yang masih dipakai item', function (): void {
    $pcs = masterUnit('PCS');
    masterItem($pcs);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/units/{$pcs->getKey()}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'MASTER_IN_USE')
        ->assertJsonPath('errors.references.0', 'items');
});

it('mendukung pagination, pencarian, sort, dan filter', function (): void {
    foreach (['PCS' => 'Pcs', 'PACK' => 'Pack', 'DUS' => 'Dus'] as $code => $name) {
        masterUnit($code, $name);
    }

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/units?per_page=2&sort=-code')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('data.0.code', 'PCS')
        ->assertJsonStructure(['data', 'meta', 'links']);

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/units?search=pack')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', 'PACK');

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/units?filter[code]=DUS')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('mencatat audit trail saat satuan dibuat dan diubah', function (): void {
    $id = $this->withHeaders($this->headers)
        ->postJson('/api/v1/units', ['code' => 'BOX', 'name' => 'Box'])
        ->json('data.id');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/units/{$id}", ['name' => 'Karton'])
        ->assertOk();

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/units/{$id}/audit")
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.event', 'updated')
        ->assertJsonPath('data.1.event', 'created')
        ->assertJsonPath('data.0.diff.name.old', 'Box')
        ->assertJsonPath('data.0.diff.name.new', 'Karton');
});
