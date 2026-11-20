<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameItem;
use App\Domain\Inventory\Services\StockOpnameService;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Warehouse;
use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);

    $this->stock = app(StockService::class);
    $this->opnames = app(StockOpnameService::class);
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
    $this->date = CarbonImmutable::parse(now()->toDateString());

    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '100',
        '10000',
        ['type' => 'GR', 'id' => 1],
        $this->date,
    );
});

/**
 * @return array<string, mixed>
 */
function stockOpnameTestPayload(Warehouse $warehouse, int $itemId, int $unitId, string $countedQty): array
{
    return [
        'doc_date' => now()->toDateString(),
        'warehouse_id' => $warehouse->getKey(),
        'notes' => 'Opname uji',
        'items' => [
            ['item_id' => $itemId, 'unit_id' => $unitId, 'counted_qty' => $countedQty],
        ],
    ];
}

it('membuat jurnal penyesuaian minus yang benar', function (): void {
    $opname = $this->opnames->create(
        stockOpnameTestPayload($this->warehouse, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '90'),
    );

    $approved = $this->opnames->approve($opname);

    expect($approved->status)->toBe('approved')
        ->and($approved->journal_id)->not->toBeNull();

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('90.0000')
        ->and((string) $balance->value)->toBe('900000.00');

    $journal = Journal::query()->findOrFail($approved->journal_id);
    assertJournalBalanced($journal);

    $lines = $journal->lines()->get()->keyBy('account_id');

    expect((string) $lines[accountByCode('5-2000')->getKey()]->debit)->toBe('100000.00')
        ->and((string) $lines[accountByCode('1-1400')->getKey()]->credit)->toBe('100000.00');
});

it('membuat jurnal penyesuaian plus yang benar', function (): void {
    $opname = $this->opnames->create(
        stockOpnameTestPayload($this->warehouse, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '105'),
    );

    $approved = $this->opnames->approve($opname);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('105.0000')
        ->and((string) $balance->value)->toBe('1050000.00');

    $journal = Journal::query()->findOrFail($approved->journal_id);
    assertJournalBalanced($journal);

    $lines = $journal->lines()->get()->keyBy('account_id');

    expect((string) $lines[accountByCode('1-1400')->getKey()]->debit)->toBe('50000.00')
        ->and((string) $lines[accountByCode('5-2000')->getKey()]->credit)->toBe('50000.00');
});

it('disapprove membalik stok dan jurnal penyesuaian', function (): void {
    $opname = $this->opnames->create(
        stockOpnameTestPayload($this->warehouse, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '90'),
    );

    $approved = $this->opnames->approve($opname);
    $journal = Journal::query()->findOrFail($approved->journal_id);

    $draft = $this->opnames->disapprove($approved);

    expect($draft->status)->toBe('draft')
        ->and($draft->journal_id)->toBeNull()
        ->and($journal->fresh()->status)->toBe(Journal::STATUS_REVERSED);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('100.0000')
        ->and((string) $balance->value)->toBe('1000000.00');
});

it('dapat approve ulang setelah disapprove tanpa menggandakan mutasi', function (): void {
    $opname = $this->opnames->create(
        stockOpnameTestPayload($this->warehouse, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '90'),
    );

    $approved = $this->opnames->approve($opname);
    $this->opnames->disapprove($approved);

    $reapproved = $this->opnames->approve($opname->fresh());

    expect($reapproved->status)->toBe('approved');

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('90.0000')
        ->and((string) $balance->value)->toBe('900000.00');

    $active = StockLedger::query()
        ->where('doc_type', 'OPN')
        ->where('doc_id', $opname->getKey())
        ->whereNull('reversal_of_id')
        ->whereNotIn(
            'id',
            StockLedger::query()->select('reversal_of_id')->whereNotNull('reversal_of_id'),
        )
        ->count();

    expect($active)->toBe(1);
});

it('menolak disapprove bila ada mutasi lanjutan', function (): void {
    $opname = $this->opnames->create(
        stockOpnameTestPayload($this->warehouse, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '90'),
    );

    $approved = $this->opnames->approve($opname);

    $this->stock->issue(
        $this->item,
        $this->warehouse,
        '5',
        ['type' => 'DO', 'id' => 50],
        $this->date,
    );

    try {
        $this->opnames->disapprove($approved);
        $this->fail('Disapprove setelah ada mutasi lanjutan seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('STOCK_REVERSAL_BLOCKED');
    }
});

it('menyetujui opname tanpa selisih tanpa jurnal dan tanpa mutasi', function (): void {
    $opname = $this->opnames->create(
        stockOpnameTestPayload($this->warehouse, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '100'),
    );

    $approved = $this->opnames->approve($opname);

    expect($approved->status)->toBe('approved')
        ->and($approved->journal_id)->toBeNull();

    expect(StockLedger::query()
        ->where('doc_type', 'OPN')
        ->where('doc_id', $opname->getKey())
        ->count())->toBe(0);

    $draft = $this->opnames->disapprove($approved);

    expect($draft->status)->toBe('draft');
});

it('mengubah dan menghapus stock opname draft', function (): void {
    $opname = $this->opnames->create(
        stockOpnameTestPayload($this->warehouse, (int) $this->item->getKey(), (int) $this->pcs->getKey(), '90'),
    );

    $updated = $this->opnames->update($opname, [
        'items' => [
            ['item_id' => $this->item->getKey(), 'unit_id' => $this->pcs->getKey(), 'counted_qty' => '95'],
        ],
    ]);

    $row = $updated->items()->firstOrFail();

    expect((string) $row->counted_qty_base)->toBe('95.0000')
        ->and((string) $row->diff_qty_base)->toBe('-5.0000');

    $this->opnames->deleteDraft($updated);

    expect(StockOpname::query()->find($opname->getKey()))->toBeNull()
        ->and(StockOpnameItem::query()
            ->where('stock_opname_id', $opname->getKey())
            ->count())->toBe(0);
});
