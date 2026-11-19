<?php

declare(strict_types=1);

use App\Domain\Master\Models\Customer;

beforeEach(function (): void {
    seedRbac();
    $this->headers = bearerFor(userWithRole('admin'));
});

it('melakukan CRUD customer dengan batas kredit', function (): void {
    $id = $this->withHeaders($this->headers)
        ->postJson('/api/v1/customers', [
            'code' => 'cus-01',
            'name' => 'Toko Kaos Bandung',
            'credit_limit' => 5000000,
            'payment_term_days' => 14,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'CUS-01')
        ->assertJsonPath('data.credit_limit', '5000000.00')
        ->json('data.id');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/customers/{$id}", ['credit_limit' => 7500000])
        ->assertOk()
        ->assertJsonPath('data.credit_limit', '7500000.00');

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/customers/{$id}")
        ->assertNoContent();
});

it('menolak batas kredit negatif', function (): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/customers', [
            'code' => 'CUS-NEG',
            'name' => 'Customer Negatif',
            'credit_limit' => -1,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['credit_limit']]);
});

it('menolak menghapus customer yang punya harga khusus', function (): void {
    $pcs = masterUnit('PCS');
    $item = masterItem($pcs);

    /** @var Customer $customer */
    $customer = Customer::query()->create(['code' => 'CUS-1', 'name' => 'Toko Satu']);

    $item->sellingPrices()->create([
        'unit_id' => $pcs->getKey(),
        'customer_id' => $customer->getKey(),
        'price' => '14000',
    ]);

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/customers/{$customer->getKey()}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'MASTER_IN_USE')
        ->assertJsonPath('errors.references.0', 'selling_prices');
});
