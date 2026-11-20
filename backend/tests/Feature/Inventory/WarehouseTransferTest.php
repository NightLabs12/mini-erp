<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Models\WarehouseTransfer;
use App\Domain\Inventory\Models\WarehouseTransferItem;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Inventory\Services\WarehouseTransferService;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Warehouse;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);

    $this->stock = app(StockService::class);
    $this->transfers = app(WarehouseTransferService::class);
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
    $this->source = Warehouse::factory()->create(['code' => 'GD-UTM', 'name' => 'Gudang Utama']);
    $this->destination = Warehouse::factory()->create(['code' => 'GD-CBG', 'name' => 'Gudang Cabang']);
    $this->date = CarbonImmutable::parse(now()->toDateString());

    $this->stock->receive(
        $this->item,
        $this->source,
        '1440',
        '10000',
        ['type' => 'GR', 'id' => 1],
        $this->date,
    );
});

/**
 * @return array<string, mixed>
 */
function warehouseTransferPayload(Warehouse $source, Warehouse $destination, int $itemId, int $unitId, string $qty): array
{
    return [
        'doc_date' => now()->toDateString(),
        'from_warehouse_id' => $source->getKey(),
        'to_warehouse_id' => $destination->getKey(),
        'notes' => 'Pindah stok uji',
        'items' => [
            ['item_id' => $itemId, 'unit_id' => $unitId, 'qty' => $qty],
        ],
    ];
}

it('memindahkan nilai persediaan tanpa jurnal dan menjaga total nilai', function (): void {
    $journalCount = Journal::query()->count();

    $transfer = $this->transfers->create(
        warehouseTransferPayload($this->source, $this->destination, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '720'),
    );

    expect($transfer->doc_no)->toStartWith('TRF/')
        ->and($transfer->status)->toBe('draft');

    $approved = $this->transfers->approve($transfer);

    expect($approved->status)->toBe('approved')
        ->and($approved->approved_at)->not->toBeNull();

    expect(Journal::query()->count())->toBe($journalCount);

    $source = $this->stock->balance($this->item, $this->source);
    $destination = $this->stock->balance($this->item, $this->destination);

    expect((string) $source->qty_base)->toBe('720.0000')
        ->and((string) $source->value)->toBe('7200000.00')
        ->and((string) $destination->qty_base)->toBe('720.0000')
        ->and((string) $destination->value)->toBe('7200000.00');

    expect(Decimal::add((string) $source->value, (string) $destination->value, Decimal::MONEY_SCALE))
        ->toBe('14400000.00');

    $item = $approved->items()->firstOrFail();

    expect((string) $item->unit_cost_base)->toBe('10000.0000')
        ->and((string) $item->total_value)->toBe('7200000.00');
});

it('approve bersifat idempoten', function (): void {
    $transfer = $this->transfers->create(
        warehouseTransferPayload($this->source, $this->destination, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '100'),
    );

    $this->transfers->approve($transfer);
    $again = $this->transfers->approve($transfer->fresh());

    expect($again->status)->toBe('approved');

    expect(StockLedger::query()
        ->where('doc_type', 'TRF')
        ->where('doc_id', $transfer->getKey())
        ->count())->toBe(2);
});

it('disapprove mengembalikan stok ke gudang asal', function (): void {
    $transfer = $this->transfers->create(
        warehouseTransferPayload($this->source, $this->destination, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '720'),
    );

    $approved = $this->transfers->approve($transfer);
    $draft = $this->transfers->disapprove($approved);

    expect($draft->status)->toBe('draft');

    $source = $this->stock->balance($this->item, $this->source);
    $destination = $this->stock->balance($this->item, $this->destination);

    expect((string) $source->qty_base)->toBe('1440.0000')
        ->and((string) $source->value)->toBe('14400000.00')
        ->and((string) $destination->qty_base)->toBe('0.0000')
        ->and((string) $destination->value)->toBe('0.00');
});

it('menolak approve bila stok gudang asal tidak cukup', function (): void {
    $transfer = $this->transfers->create(
        warehouseTransferPayload($this->source, $this->destination, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '2000'),
    );

    try {
        $this->transfers->approve($transfer);
        $this->fail('Transfer melebihi stok seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('STOCK_INSUFFICIENT');
    }

    expect((string) $this->stock->balance($this->item, $this->source)->qty_base)->toBe('1440.0000');
});

it('menolak disapprove bila ada mutasi lanjutan di gudang tujuan', function (): void {
    $transfer = $this->transfers->create(
        warehouseTransferPayload($this->source, $this->destination, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '720'),
    );

    $approved = $this->transfers->approve($transfer);

    $this->stock->issue(
        $this->item,
        $this->destination,
        '10',
        ['type' => 'DO', 'id' => 99],
        $this->date,
    );

    try {
        $this->transfers->disapprove($approved);
        $this->fail('Disapprove setelah ada mutasi lanjutan seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('STOCK_REVERSAL_BLOCKED');
    }
});

it('menolak approve bila periode dokumen sudah ditutup', function (): void {
    $transfer = $this->transfers->create(
        warehouseTransferPayload($this->source, $this->destination, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '100'),
    );

    accountingPeriod((int) $this->date->year, (int) $this->date->month, 'closed');

    try {
        $this->transfers->approve($transfer);
        $this->fail('Approve pada periode closed seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_CLOSED');
    }
});

it('menolak transfer dengan gudang asal dan tujuan sama', function (): void {
    try {
        $this->transfers->create(
            warehouseTransferPayload($this->source, $this->source, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '10'),
        );
        $this->fail('Transfer ke gudang yang sama seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('SAME_WAREHOUSE_TRANSFER');
    }
});

it('mengubah dan menghapus transfer draft', function (): void {
    $transfer = $this->transfers->create(
        warehouseTransferPayload($this->source, $this->destination, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '10'),
    );

    $updated = $this->transfers->update($transfer, [
        'notes' => 'Catatan baru',
        'items' => [
            ['item_id' => $this->item->getKey(), 'unit_id' => $this->pcs->getKey(), 'qty' => '20'],
        ],
    ]);

    expect($updated->notes)->toBe('Catatan baru')
        ->and((string) $updated->items()->firstOrFail()->qty_base)->toBe('20.0000');

    $this->transfers->deleteDraft($updated);

    expect(WarehouseTransfer::query()->find($transfer->getKey()))->toBeNull()
        ->and(WarehouseTransferItem::query()
            ->where('warehouse_transfer_id', $transfer->getKey())
            ->count())->toBe(0);
});
