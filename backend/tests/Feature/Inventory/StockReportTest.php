<?php

declare(strict_types=1);

use App\Domain\Inventory\Services\StockReportService;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Warehouse;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);

    $this->stock = app(StockService::class);
    $this->reports = app(StockReportService::class);
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

    $this->previousMonth = CarbonImmutable::parse(now()->startOfMonth()->subMonth()->toDateString());
    accountingPeriod((int) $this->previousMonth->year, (int) $this->previousMonth->month);
    $this->currentMonth = CarbonImmutable::parse(now()->startOfMonth()->toDateString());
    $this->from = now()->startOfMonth()->toDateString();
    $this->to = now()->endOfMonth()->toDateString();

    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '10',
        '1000',
        ['type' => 'GR', 'id' => 1],
        $this->previousMonth,
    );
    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '5',
        '1200',
        ['type' => 'GR', 'id' => 2],
        $this->currentMonth,
    );
    $this->stock->issue(
        $this->item,
        $this->warehouse,
        '3',
        ['type' => 'DO', 'id' => 1],
        $this->currentMonth,
    );
});

it('menampilkan kartu stok dengan saldo awal dan saldo berjalan', function (): void {
    $report = $this->reports->stockCard(
        (int) $this->item->getKey(),
        (int) $this->warehouse->getKey(),
        $this->from,
        $this->to,
    );

    $warehouse = $report['warehouses'][0];

    expect($warehouse['opening_qty'])->toBe('10.0000')
        ->and($warehouse['opening_value'])->toBe('10000.00')
        ->and($warehouse['opening_avg_cost'])->toBe('1000.0000')
        ->and($warehouse['lines'])->toHaveCount(2)
        ->and($warehouse['lines'][0]['running_qty'])->toBe('15.0000')
        ->and($warehouse['lines'][0]['running_value'])->toBe('16000.00')
        ->and($warehouse['lines'][1]['running_qty'])->toBe('12.0000')
        ->and($warehouse['lines'][1]['running_value'])->toBe('12800.00')
        ->and($warehouse['lines'][1]['signed_value'])->toBe('-3200.00')
        ->and($warehouse['closing_qty'])->toBe('12.0000')
        ->and($warehouse['closing_value'])->toBe('12800.00');
});

it('menampilkan posisi stok awal, masuk, keluar, dan akhir', function (): void {
    $report = $this->reports->stockPosition(
        (int) $this->item->getKey(),
        (int) $this->warehouse->getKey(),
        $this->from,
        $this->to,
    );

    $row = $report['items'][0];

    expect($row['opening_qty'])->toBe('10.0000')
        ->and($row['opening_value'])->toBe('10000.00')
        ->and($row['in_qty'])->toBe('5.0000')
        ->and($row['in_value'])->toBe('6000.00')
        ->and($row['out_qty'])->toBe('3.0000')
        ->and($row['out_value'])->toBe('3200.00')
        ->and($row['closing_qty'])->toBe('12.0000')
        ->and($row['closing_value'])->toBe('12800.00');
});

it('menampilkan mutasi stok beserta totalnya', function (): void {
    $report = $this->reports->stockMovement(
        (int) $this->item->getKey(),
        (int) $this->warehouse->getKey(),
        $this->from,
        $this->to,
    );

    expect($report['lines'])->toHaveCount(2)
        ->and($report['totals']['count'])->toBe(2)
        ->and($report['totals']['qty_in_base'])->toBe('5.0000')
        ->and($report['totals']['qty_out_base'])->toBe('3.0000')
        ->and($report['totals']['value_in'])->toBe('6000.00')
        ->and($report['totals']['value_out'])->toBe('3200.00')
        ->and($report['totals']['net_value'])->toBe('2800.00');
});
