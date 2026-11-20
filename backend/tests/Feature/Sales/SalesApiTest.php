<?php

declare(strict_types=1);

use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Warehouse;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    seedRbac();
    $this->seed(AccountingSeeder::class);

    $this->adminHeaders = bearerFor(userWithRole('admin'));
    $this->salesHeaders = bearerFor(userWithRole('sales'));
    $this->warehouseHeaders = bearerFor(userWithRole('warehouse'));
    $this->viewerHeaders = bearerFor(userWithRole('viewer'));

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
    $this->customer = Customer::factory()->create([
        'code' => 'CUS-1',
        'name' => 'Customer Satu',
        'payment_term_days' => 30,
        'credit_limit' => '0.00',
    ]);
    $this->date = now()->toDateString();

    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->dus->getKey(),
        'customer_id' => null,
        'price' => '2160000',
    ]);

    app(StockService::class)->receive(
        $this->item,
        $this->warehouse,
        '2880',
        '11000',
        ['type' => 'GR', 'id' => 1],
        $this->date,
    );
});

it('menjalankan alur sales lewat API, laporan, dan cetak PDF', function (): void {
    $so = $this->withHeaders($this->salesHeaders)
        ->postJson('/api/v1/sales-orders', [
            'doc_date' => $this->date,
            'customer_id' => $this->customer->getKey(),
            'warehouse_id' => $this->warehouse->getKey(),
            'items' => [
                [
                    'item_id' => $this->item->getKey(),
                    'unit_id' => $this->dus->getKey(),
                    'qty' => '5',
                    'discount_pct' => '0',
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total', '11988000.00')
        ->assertJsonPath('data.items.0.unit_price', '2160000.0000');

    $soId = (int) $so->json('data.id');
    $soItemId = (int) $so->json('data.items.0.id');

    $this->withHeaders($this->salesHeaders)
        ->postJson("/api/v1/sales-orders/{$soId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $do = $this->withHeaders($this->warehouseHeaders)
        ->postJson('/api/v1/delivery-orders', [
            'doc_date' => $this->date,
            'sales_order_id' => $soId,
            'items' => [
                ['sales_order_item_id' => $soItemId, 'qty' => '5'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    $doId = (int) $do->json('data.id');
    $doItemId = (int) $do->json('data.items.0.id');

    $this->withHeaders($this->warehouseHeaders)
        ->postJson("/api/v1/delivery-orders/{$doId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.items.0.cogs_unit_cost_base', '11000.0000');

    $si = $this->withHeaders($this->salesHeaders)
        ->postJson('/api/v1/sales-invoices', [
            'doc_date' => $this->date,
            'customer_id' => $this->customer->getKey(),
            'items' => [
                ['delivery_order_item_id' => $doItemId, 'qty' => '5'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total', '11988000.00');

    $siId = (int) $si->json('data.id');

    $this->withHeaders($this->salesHeaders)
        ->postJson("/api/v1/sales-invoices/{$siId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.mode', 'final');

    $payment = $this->withHeaders($this->salesHeaders)
        ->postJson('/api/v1/customer-payments', [
            'doc_date' => $this->date,
            'customer_id' => $this->customer->getKey(),
            'method' => 'bank',
            'amount' => '11988000.00',
            'allocations' => [
                ['sales_invoice_id' => $siId, 'amount' => '11988000.00'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    $paymentId = (int) $payment->json('data.id');

    $this->withHeaders($this->salesHeaders)
        ->postJson("/api/v1/customer-payments/{$paymentId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->withHeaders($this->salesHeaders)
        ->getJson("/api/v1/sales-invoices/{$siId}")
        ->assertOk()
        ->assertJsonPath('data.paid_amount', '11988000.00')
        ->assertJsonPath('data.remaining', '0.00');

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    $this->withHeaders($this->salesHeaders)
        ->getJson("/api/v1/reports/sales-summary?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.totals.total', '11988000.00');

    $this->withHeaders($this->salesHeaders)
        ->getJson('/api/v1/reports/ar-outstanding?as_of='.$this->date)
        ->assertOk()
        ->assertJsonPath('data.totals.count', 0);

    $this->withHeaders($this->salesHeaders)
        ->getJson("/api/v1/reports/so-outstanding?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.totals.count', 0);

    $this->withHeaders($this->salesHeaders)
        ->getJson('/api/v1/reports/ar-aging?as_of='.$this->date)
        ->assertOk()
        ->assertJsonPath('data.totals.count', 0);

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->adminHeaders)
        ->getJson("/api/v1/reports/income-statement?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.gross_profit', '2880000.00');

    $this->withHeaders($this->salesHeaders)
        ->get("/api/v1/delivery-orders/{$doId}/print")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->withHeaders($this->salesHeaders)
        ->get("/api/v1/sales-invoices/{$siId}/print")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('menegakkan RBAC pada endpoint sales', function (): void {
    $payload = [
        'doc_date' => $this->date,
        'customer_id' => $this->customer->getKey(),
        'warehouse_id' => $this->warehouse->getKey(),
        'items' => [
            [
                'item_id' => $this->item->getKey(),
                'unit_id' => $this->dus->getKey(),
                'qty' => '1',
                'discount_pct' => '0',
            ],
        ],
    ];

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->viewerHeaders)
        ->postJson('/api/v1/sales-orders', $payload)
        ->assertStatus(403);

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->warehouseHeaders)
        ->postJson('/api/v1/sales-orders', $payload)
        ->assertStatus(403);

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->viewerHeaders)
        ->getJson('/api/v1/sales-orders')
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->warehouseHeaders)
        ->getJson('/api/v1/delivery-orders')
        ->assertStatus(403);

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->salesHeaders)
        ->postJson('/api/v1/sales-orders', $payload)
        ->assertCreated();
});
