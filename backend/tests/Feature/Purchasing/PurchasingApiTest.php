<?php

declare(strict_types=1);

use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Supplier;
use App\Domain\Master\Models\Warehouse;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    seedRbac();
    $this->seed(AccountingSeeder::class);

    $this->adminHeaders = bearerFor(userWithRole('admin'));
    $this->viewerHeaders = bearerFor(userWithRole('viewer'));
    $this->warehouseHeaders = bearerFor(userWithRole('warehouse'));

    $this->pcs = masterUnit('PCS', 'Pcs');
    $this->dus = masterUnit('DUS', 'Dus');
    $this->category = ItemCategory::query()->create([
        'code' => 'KAOS',
        'name' => 'Kaos Jadi',
        'item_type' => 'trading',
    ]);
    $this->item = masterItem($this->pcs, [
        'sku' => 'KAOS-POLOS',
        'name' => 'Kaos Polos',
        'category_id' => $this->category->getKey(),
    ]);
    addConversion($this->item, $this->dus, '144');

    $this->warehouse = Warehouse::factory()->create(['code' => 'GD-UTM', 'name' => 'Gudang Utama']);
    $this->supplier = Supplier::factory()->create([
        'code' => 'SUP-1',
        'name' => 'Supplier Satu',
        'payment_term_days' => 30,
    ]);
    $this->date = now()->toDateString();
});

it('menjalankan alur purchasing lewat API, laporan, dan cetak PDF', function (): void {
    $po = $this->withHeaders($this->adminHeaders)
        ->postJson('/api/v1/purchase-orders', [
            'doc_date' => $this->date,
            'supplier_id' => $this->supplier->getKey(),
            'warehouse_id' => $this->warehouse->getKey(),
            'items' => [
                [
                    'item_id' => $this->item->getKey(),
                    'unit_id' => $this->dus->getKey(),
                    'qty' => '10',
                    'unit_price' => '1440000',
                    'discount_pct' => '0',
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total', '15984000.00');

    $poId = (int) $po->json('data.id');
    $poItemId = (int) $po->json('data.items.0.id');

    $this->withHeaders($this->adminHeaders)
        ->postJson("/api/v1/purchase-orders/{$poId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $gr = $this->withHeaders($this->warehouseHeaders)
        ->postJson('/api/v1/goods-receipts', [
            'doc_date' => $this->date,
            'purchase_order_id' => $poId,
            'items' => [
                ['purchase_order_item_id' => $poItemId, 'qty' => '10'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    $grId = (int) $gr->json('data.id');
    $grItemId = (int) $gr->json('data.items.0.id');

    $this->withHeaders($this->warehouseHeaders)
        ->postJson("/api/v1/goods-receipts/{$grId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $pi = $this->withHeaders($this->adminHeaders)
        ->postJson('/api/v1/purchase-invoices', [
            'doc_date' => $this->date,
            'supplier_id' => $this->supplier->getKey(),
            'items' => [
                ['goods_receipt_item_id' => $grItemId, 'qty' => '10', 'unit_price' => '1440000'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total', '15984000.00');

    $piId = (int) $pi->json('data.id');

    $this->withHeaders($this->adminHeaders)
        ->postJson("/api/v1/purchase-invoices/{$piId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $payment = $this->withHeaders($this->adminHeaders)
        ->postJson('/api/v1/supplier-payments', [
            'doc_date' => $this->date,
            'supplier_id' => $this->supplier->getKey(),
            'method' => 'bank',
            'amount' => '15984000.00',
            'allocations' => [
                ['purchase_invoice_id' => $piId, 'amount' => '15984000.00'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    $paymentId = (int) $payment->json('data.id');

    $this->withHeaders($this->adminHeaders)
        ->postJson("/api/v1/supplier-payments/{$paymentId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->withHeaders($this->adminHeaders)
        ->getJson("/api/v1/purchase-invoices/{$piId}")
        ->assertOk()
        ->assertJsonPath('data.paid_amount', '15984000.00')
        ->assertJsonPath('data.remaining', '0.00');

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    $this->withHeaders($this->adminHeaders)
        ->getJson("/api/v1/reports/purchase-summary?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.totals.total', '15984000.00');

    $this->withHeaders($this->adminHeaders)
        ->getJson('/api/v1/reports/ap-outstanding?as_of='.$this->date)
        ->assertOk()
        ->assertJsonPath('data.totals.count', 0);

    $this->withHeaders($this->adminHeaders)
        ->getJson("/api/v1/reports/po-outstanding?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.totals.count', 0);

    $this->withHeaders($this->adminHeaders)
        ->getJson('/api/v1/reports/ap-aging?as_of='.$this->date)
        ->assertOk()
        ->assertJsonPath('data.totals.count', 0);

    $this->withHeaders($this->adminHeaders)
        ->get("/api/v1/purchase-orders/{$poId}/print")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('menegakkan RBAC pada endpoint purchasing', function (): void {
    $payload = [
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'warehouse_id' => $this->warehouse->getKey(),
        'items' => [
            [
                'item_id' => $this->item->getKey(),
                'unit_id' => $this->dus->getKey(),
                'qty' => '1',
                'unit_price' => '1440000',
            ],
        ],
    ];

    $this->withHeaders($this->viewerHeaders)
        ->postJson('/api/v1/purchase-orders', $payload)
        ->assertStatus(403);

    $this->withHeaders($this->warehouseHeaders)
        ->postJson('/api/v1/purchase-orders', $payload)
        ->assertStatus(403);

    $this->withHeaders($this->viewerHeaders)
        ->getJson('/api/v1/purchase-orders')
        ->assertOk();

    $this->withHeaders($this->warehouseHeaders)
        ->getJson('/api/v1/goods-receipts')
        ->assertOk();
});
