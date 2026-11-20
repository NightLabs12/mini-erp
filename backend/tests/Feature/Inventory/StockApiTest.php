<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\WarehouseTransfer;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Warehouse;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    seedRbac();
    $this->seed(AccountingSeeder::class);

    $this->adminHeaders = bearerFor(userWithRole('admin'));
    $this->warehouseHeaders = bearerFor(userWithRole('warehouse'));
    $this->purchasingHeaders = bearerFor(userWithRole('purchasing'));
    $this->viewerHeaders = bearerFor(userWithRole('viewer'));

    $this->pcs = masterUnit('PCS', 'Pcs');
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
    $this->warehouse = Warehouse::factory()->create(['code' => 'GD-UTM', 'name' => 'Gudang Utama']);
    $this->other = Warehouse::factory()->create(['code' => 'GD-CBG', 'name' => 'Gudang Cabang']);
    $this->date = now()->toDateString();

    app(StockService::class)->receive(
        $this->item,
        $this->warehouse,
        '100',
        '10000',
        ['type' => 'GR', 'id' => 1],
        $this->date,
    );
});

it('menyajikan saldo, ledger, stok per item, dan laporan persediaan', function (): void {
    $this->withHeaders($this->adminHeaders)
        ->getJson('/api/v1/stock/balances?filter[item_id]='.$this->item->getKey())
        ->assertOk()
        ->assertJsonPath('data.0.item.sku', 'KAOS-POLOS')
        ->assertJsonPath('data.0.qty_base', '100.0000')
        ->assertJsonPath('data.0.value', '1000000.00');

    $this->withHeaders($this->adminHeaders)
        ->getJson('/api/v1/stock/ledger?filter[item_id]='.$this->item->getKey())
        ->assertOk()
        ->assertJsonPath('data.0.doc_type', 'GR')
        ->assertJsonPath('data.0.qty_in_base', '100.0000');

    $this->withHeaders($this->adminHeaders)
        ->getJson("/api/v1/items/{$this->item->getKey()}/stock")
        ->assertOk()
        ->assertJsonPath('data.total_qty_base', '100.0000')
        ->assertJsonPath('data.total_value', '1000000.00')
        ->assertJsonPath('data.balances.0.warehouse.code', 'GD-UTM');

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    $this->withHeaders($this->adminHeaders)
        ->getJson("/api/v1/reports/stock-card?item_id={$this->item->getKey()}&from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.warehouses.0.closing_qty', '100.0000')
        ->assertJsonPath('data.warehouses.0.closing_value', '1000000.00');

    $this->withHeaders($this->adminHeaders)
        ->getJson("/api/v1/reports/stock-position?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.items.0.closing_qty', '100.0000');

    $this->withHeaders($this->adminHeaders)
        ->getJson("/api/v1/reports/stock-movement?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.totals.qty_in_base', '100.0000')
        ->assertJsonPath('data.totals.value_in', '1000000.00');
});

it('melakukan CRUD transfer gudang lewat API dan mencatat audit', function (): void {
    $created = $this->withHeaders($this->warehouseHeaders)
        ->postJson('/api/v1/warehouse-transfers', [
            'doc_date' => $this->date,
            'from_warehouse_id' => $this->warehouse->getKey(),
            'to_warehouse_id' => $this->other->getKey(),
            'notes' => 'Transfer API',
            'items' => [
                ['item_id' => $this->item->getKey(), 'unit_id' => $this->pcs->getKey(), 'qty' => '10'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', WarehouseTransfer::STATUS_DRAFT)
        ->assertJsonCount(1, 'data.items');

    expect($created->json('data.doc_no'))->toStartWith('TRF/');

    $id = (int) $created->json('data.id');

    $this->withHeaders($this->warehouseHeaders)
        ->postJson("/api/v1/warehouse-transfers/{$id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', WarehouseTransfer::STATUS_APPROVED);

    $events = collect(
        $this->withHeaders($this->warehouseHeaders)
            ->getJson("/api/v1/warehouse-transfers/{$id}/audit")
            ->assertOk()
            ->json('data')
    )->pluck('event');

    expect($events)->toContain('created')->toContain('approved');

    $this->withHeaders($this->warehouseHeaders)
        ->postJson("/api/v1/warehouse-transfers/{$id}/disapprove")
        ->assertOk()
        ->assertJsonPath('data.status', WarehouseTransfer::STATUS_DRAFT);

    $this->withHeaders($this->warehouseHeaders)
        ->deleteJson("/api/v1/warehouse-transfers/{$id}")
        ->assertNoContent();
});

it('melakukan CRUD stock opname lewat API dan membentuk jurnal', function (): void {
    $created = $this->withHeaders($this->warehouseHeaders)
        ->postJson('/api/v1/stock-opnames', [
            'doc_date' => $this->date,
            'warehouse_id' => $this->warehouse->getKey(),
            'notes' => 'Opname API',
            'items' => [
                ['item_id' => $this->item->getKey(), 'unit_id' => $this->pcs->getKey(), 'counted_qty' => '95'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', StockOpname::STATUS_DRAFT)
        ->assertJsonPath('data.items.0.diff_qty_base', '-5.0000');

    expect($created->json('data.doc_no'))->toStartWith('OPN/');

    $id = (int) $created->json('data.id');

    $approved = $this->withHeaders($this->warehouseHeaders)
        ->postJson("/api/v1/stock-opnames/{$id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', StockOpname::STATUS_APPROVED);

    expect($approved->json('data.journal_id'))->not->toBeNull();

    $this->withHeaders($this->warehouseHeaders)
        ->getJson("/api/v1/stock-opnames/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', StockOpname::STATUS_APPROVED)
        ->assertJsonPath('data.journal.source_type', 'stock_opname');

    $this->withHeaders($this->warehouseHeaders)
        ->postJson("/api/v1/stock-opnames/{$id}/disapprove")
        ->assertOk()
        ->assertJsonPath('data.status', StockOpname::STATUS_DRAFT);
});

it('menegakkan RBAC pada endpoint persediaan', function (): void {
    $payload = [
        'doc_date' => $this->date,
        'from_warehouse_id' => $this->warehouse->getKey(),
        'to_warehouse_id' => $this->other->getKey(),
        'items' => [
            ['item_id' => $this->item->getKey(), 'unit_id' => $this->pcs->getKey(), 'qty' => '1'],
        ],
    ];

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->purchasingHeaders)
        ->postJson('/api/v1/warehouse-transfers', $payload)
        ->assertStatus(403);

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->viewerHeaders)
        ->postJson('/api/v1/warehouse-transfers', $payload)
        ->assertStatus(403);

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->viewerHeaders)
        ->getJson('/api/v1/stock/balances')
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->purchasingHeaders)
        ->getJson('/api/v1/stock/ledger')
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withHeaders($this->warehouseHeaders)
        ->postJson('/api/v1/warehouse-transfers', $payload)
        ->assertCreated();
});
