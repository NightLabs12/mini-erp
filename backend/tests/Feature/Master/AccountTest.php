<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Core\Models\AccountSetting;
use App\Domain\Master\Models\ItemCategory;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    seedRbac();
    $this->headers = bearerFor(userWithRole('admin'));
});

it('melakukan CRUD akun beserta hierarki', function (): void {
    $created = $this->withHeaders($this->headers)
        ->postJson('/api/v1/accounts', [
            'code' => '9-1000',
            'name' => 'Akun Uji',
            'type' => 'expense',
            'normal_balance' => 'D',
            'is_postable' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', '9-1000')
        ->assertJsonPath('data.level', 1);

    $id = (int) $created->json('data.id');

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/accounts?search=9-1000')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Akun Uji');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/accounts/{$id}", [
            'name' => 'Akun Uji Diubah',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Akun Uji Diubah')
        ->assertJsonPath('data.is_active', false);

    $child = $this->withHeaders($this->headers)
        ->postJson('/api/v1/accounts', [
            'code' => '9-1100',
            'name' => 'Akun Anak',
            'type' => 'expense',
            'normal_balance' => 'D',
            'parent_id' => $id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.level', 2);

    $childId = (int) $child->json('data.id');

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/accounts/{$id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'ACCOUNT_IN_USE');

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/accounts/{$childId}")
        ->assertNoContent();

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/accounts/{$id}")
        ->assertNoContent();
});

it('memvalidasi kode, tipe, dan saldo normal akun', function (): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/accounts', [
            'code' => '9-2000',
            'name' => 'Salah',
            'type' => 'unknown',
            'normal_balance' => 'X',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['type', 'normal_balance']]);
});

it('menolak parent yang membentuk siklus', function (): void {
    $this->seed(AccountingSeeder::class);

    $parent = Account::query()->where('code', '1-0000')->firstOrFail();
    $child = Account::query()->where('code', '1-1000')->firstOrFail();

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/accounts/{$parent->getKey()}", ['parent_id' => $child->getKey()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ACCOUNT_PARENT_CYCLE');
});

it('menolak menghapus akun yang dipakai kategori item atau setting', function (): void {
    $this->seed(AccountingSeeder::class);

    $account = Account::query()->where('code', '1-1400')->firstOrFail();

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/accounts/{$account->getKey()}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'ACCOUNT_IN_USE');

    $unused = Account::query()->where('code', '6-9000')->firstOrFail();

    expect(ItemCategory::query()->where('inventory_account_id', $unused->getKey())->exists())->toBeFalse()
        ->and(AccountSetting::query()->where('account_id', $unused->getKey())->exists())->toBeFalse();
});

it('menolak akses tanpa permission master akun', function (): void {
    $this->withHeaders(bearerFor(userWithRole('viewer')))
        ->postJson('/api/v1/accounts', [
            'code' => '9-3000',
            'name' => 'Tidak Boleh',
            'type' => 'expense',
            'normal_balance' => 'D',
        ])
        ->assertStatus(403);
});
