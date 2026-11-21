<?php

declare(strict_types=1);

use App\Domain\Core\Services\SettingService;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Warehouse;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);

    $this->stock = app(StockService::class);
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
    $this->warehouse = Warehouse::factory()->create([
        'code' => 'GD-UTM',
        'name' => 'Gudang Utama',
    ]);
    $this->otherWarehouse = Warehouse::factory()->create([
        'code' => 'GD-CBG',
        'name' => 'Gudang Cabang',
    ]);
    $this->date = CarbonImmutable::parse(now()->toDateString());
});

it('menghitung moving average dan HPP sesuai spesifikasi', function (): void {
    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '1440',
        '10000',
        ['type' => 'GR', 'id' => 1],
        $this->date,
    );
    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '1440',
        '12000',
        ['type' => 'GR', 'id' => 2],
        $this->date,
    );

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('2880.0000')
        ->and((string) $balance->avg_cost)->toBe('11000.0000')
        ->and((string) $balance->value)->toBe('31680000.00');

    $cost = $this->stock->issue(
        $this->item,
        $this->warehouse,
        '720',
        ['type' => 'DO', 'id' => 1],
        $this->date,
    );

    expect($cost)->toBe('11000.0000');

    $balance->refresh();

    expect((string) $balance->qty_base)->toBe('2160.0000')
        ->and((string) $balance->value)->toBe('23760000.00')
        ->and((string) $balance->avg_cost)->toBe('11000.0000');

    $out = StockLedger::query()
        ->where('doc_type', 'DO')
        ->where('doc_id', 1)
        ->firstOrFail();

    expect((string) $out->unit_cost)->toBe('11000.0000')
        ->and((string) $out->total_value)->toBe('7920000.00');
});

it('menolak stok negatif dengan kode STOCK_INSUFFICIENT', function (): void {
    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '10',
        '1000',
        ['type' => 'GR', 'id' => 1],
        $this->date,
    );

    try {
        $this->stock->issue(
            $this->item,
            $this->warehouse,
            '11',
            ['type' => 'DO', 'id' => 1],
            $this->date,
        );

        $this->fail('Pengeluaran melebihi saldo seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('STOCK_INSUFFICIENT')
            ->and($exception->status())->toBe(422)
            ->and($exception->getMessage())->toContain('Kaos Polos')
            ->toContain('Gudang Utama')
            ->toContain('10.0000')
            ->toContain('11.0000');
    }

    expect((string) $this->stock->balance($this->item, $this->warehouse)->qty_base)->toBe('10.0000');
});

it('mengizinkan stok negatif bila setting allow_negative_stock aktif', function (): void {
    $settings = app(SettingService::class);
    $settings->set('allow_negative_stock', 'true');

    $this->stock->issue(
        $this->item,
        $this->warehouse,
        '5',
        ['type' => 'DO', 'id' => 1],
        $this->date,
    );

    expect((string) $this->stock->balance($this->item, $this->warehouse)->qty_base)->toBe('-5.0000');
});

it('membalik mutasi dengan baris pembalik tanpa menghapus ledger asal', function (): void {
    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '100',
        '1000',
        ['type' => 'GR', 'id' => 10],
        $this->date,
    );
    $this->stock->issue(
        $this->item,
        $this->warehouse,
        '20',
        ['type' => 'DO', 'id' => 20],
        $this->date,
    );

    $reversals = $this->stock->reverse(['type' => 'DO', 'id' => 20], $this->date);

    expect($reversals)->toHaveCount(1);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('100.0000')
        ->and((string) $balance->value)->toBe('100000.00')
        ->and((string) $reversals[0]->qty_in_base)->toBe('20.0000')
        ->and((string) $reversals[0]->qty_out_base)->toBe('0.0000')
        ->and($reversals[0]->reversal_of_id)->not->toBeNull();

    expect(StockLedger::query()->where('doc_type', 'DO')->where('doc_id', 20)->count())->toBe(2);
});

it('menolak reverse bila ada mutasi lanjutan pada item dan gudang yang sama', function (): void {
    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '100',
        '1000',
        ['type' => 'GR', 'id' => 10],
        $this->date,
    );
    $this->stock->issue(
        $this->item,
        $this->warehouse,
        '20',
        ['type' => 'DO', 'id' => 20],
        $this->date,
    );

    try {
        $this->stock->reverse(['type' => 'GR', 'id' => 10], $this->date);
        $this->fail('Reverse GR setelah ada pengeluaran seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('STOCK_REVERSAL_BLOCKED')
            ->and($exception->status())->toBe(409);
    }
});

it('menolak reverse ganda pada dokumen yang sama', function (): void {
    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '10',
        '1000',
        ['type' => 'GR', 'id' => 1],
        $this->date,
    );
    $this->stock->issue(
        $this->item,
        $this->warehouse,
        '5',
        ['type' => 'DO', 'id' => 20],
        $this->date,
    );

    $this->stock->reverse(['type' => 'DO', 'id' => 20], $this->date);

    try {
        $this->stock->reverse(['type' => 'DO', 'id' => 20], $this->date);
        $this->fail('Reverse kedua seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('STOCK_ALREADY_REVERSED');
    }
});

it('menolak reverse bila periode dokumen sudah ditutup', function (): void {
    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '10',
        '1000',
        ['type' => 'GR', 'id' => 10],
        $this->date,
    );

    accountingPeriod((int) $this->date->year, (int) $this->date->month, 'closed');

    try {
        $this->stock->reverse(['type' => 'GR', 'id' => 10], $this->date);
        $this->fail('Reverse pada periode closed seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_CLOSED');
    }
});

it('menolak mutasi langsung pada periode yang sudah ditutup', function (): void {
    accountingPeriod((int) $this->date->year, (int) $this->date->month, 'closed');

    try {
        $this->stock->receive(
            $this->item,
            $this->warehouse,
            '10',
            '1000',
            ['type' => 'GR', 'id' => 1],
            $this->date,
        );
        $this->fail('Receive pada periode closed seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_CLOSED');
    }

    try {
        $this->stock->issue(
            $this->item,
            $this->warehouse,
            '1',
            ['type' => 'DO', 'id' => 1],
            $this->date,
        );
        $this->fail('Issue pada periode closed seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_CLOSED');
    }
});

it('menjaga balance_value konsisten dengan SUM ledger', function (): void {
    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '100',
        '1000',
        ['type' => 'GR', 'id' => 1],
        $this->date,
    );
    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '50',
        '1200',
        ['type' => 'GR', 'id' => 2],
        $this->date,
    );
    $this->stock->issue(
        $this->item,
        $this->warehouse,
        '30',
        ['type' => 'DO', 'id' => 1],
        $this->date,
    );
    $this->stock->issue(
        $this->item,
        $this->warehouse,
        '20',
        ['type' => 'DO', 'id' => 2],
        $this->date,
    );

    $rows = StockLedger::query()
        ->where('item_id', $this->item->getKey())
        ->where('warehouse_id', $this->warehouse->getKey())
        ->orderBy('id')
        ->get();

    $sumQty = '0.0000';
    $sumValue = '0.00';

    foreach ($rows as $row) {
        $signed = Decimal::compare((string) $row->qty_in_base, '0', Decimal::QTY_SCALE) > 0
            ? (string) $row->total_value
            : Decimal::mul((string) $row->total_value, '-1', Decimal::MONEY_SCALE);

        $sumQty = Decimal::add(
            $sumQty,
            Decimal::sub((string) $row->qty_in_base, (string) $row->qty_out_base, Decimal::QTY_SCALE),
            Decimal::QTY_SCALE,
        );
        $sumValue = Decimal::add($sumValue, $signed, Decimal::MONEY_SCALE);

        expect(Decimal::compare((string) $row->balance_qty, $sumQty, Decimal::QTY_SCALE))->toBe(0)
            ->and(Decimal::compare((string) $row->balance_value, $sumValue, Decimal::MONEY_SCALE))->toBe(0);
    }

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect(Decimal::compare((string) $balance->qty_base, $sumQty, Decimal::QTY_SCALE))->toBe(0)
        ->and(Decimal::compare((string) $balance->value, $sumValue, Decimal::MONEY_SCALE))->toBe(0);
});
