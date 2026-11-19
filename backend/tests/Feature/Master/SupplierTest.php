<?php

declare(strict_types=1);

beforeEach(function (): void {
    seedRbac();
    $this->headers = bearerFor(userWithRole('admin'));
});

it('melakukan CRUD supplier dengan termin pembayaran', function (): void {
    $id = $this->withHeaders($this->headers)
        ->postJson('/api/v1/suppliers', [
            'code' => 'sup-01',
            'name' => 'PT Benang Nusantara',
            'email' => 'sales@benang.test',
            'phone' => '081200000001',
            'tax_id' => '01.234.567.8-901.000',
            'payment_term_days' => 45,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'SUP-01')
        ->assertJsonPath('data.payment_term_days', 45)
        ->json('data.id');

    $this->withHeaders($this->headers)
        ->putJson("/api/v1/suppliers/{$id}", ['name' => 'PT Benang Nusantara Jaya'])
        ->assertOk()
        ->assertJsonPath('data.name', 'PT Benang Nusantara Jaya');

    $this->withHeaders($this->headers)
        ->deleteJson("/api/v1/suppliers/{$id}")
        ->assertNoContent();
});

it('memvalidasi email dan termin', function (): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/suppliers', [
            'code' => 'SUP-X',
            'name' => 'Supplier X',
            'email' => 'bukan-email',
            'payment_term_days' => 500,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['email', 'payment_term_days']]);
});

it('mencari supplier berdasarkan nama dan email', function (): void {
    $this->withHeaders($this->headers)
        ->postJson('/api/v1/suppliers', ['code' => 'SUP-A', 'name' => 'Kain Jaya', 'email' => 'kain@jaya.test'])
        ->assertCreated();

    $this->withHeaders($this->headers)
        ->postJson('/api/v1/suppliers', ['code' => 'SUP-B', 'name' => 'Benang Makmur', 'email' => 'benang@makmur.test'])
        ->assertCreated();

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/suppliers?search=makmur')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', 'SUP-B');
});
