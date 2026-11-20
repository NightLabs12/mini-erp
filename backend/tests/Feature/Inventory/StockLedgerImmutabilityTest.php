<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Warehouse;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);

    $this->stock = app(StockService::class);
    $this->pcs = masterUnit('PCS', 'Pcs');
    $this->category = ItemCategory::query()->create([
        'code' => 'LEDGER',
        'name' => 'Ledger',
        'item_type' => 'trading',
    ]);
    $this->item = masterItem($this->pcs, [
        'sku' => 'LEDGER-1',
        'category_id' => $this->category->getKey(),
    ]);
    $this->warehouse = Warehouse::factory()->create(['code' => 'GD-LED']);

    $this->stock->receive(
        $this->item,
        $this->warehouse,
        '10',
        '1000',
        ['type' => 'GR', 'id' => 1],
        CarbonImmutable::parse(now()->toDateString()),
    );
});

it('menolak UPDATE pada stock_ledger lewat trigger database', function (): void {
    $row = StockLedger::query()->firstOrFail();

    expect(function () use ($row): void {
        DB::table('stock_ledger')
            ->where('id', $row->getKey())
            ->update(['qty_in_base' => '0.0000']);
    })->toThrow(QueryException::class);
});

it('menolak DELETE pada stock_ledger lewat trigger database', function (): void {
    $row = StockLedger::query()->firstOrFail();

    expect(function () use ($row): void {
        DB::table('stock_ledger')->where('id', $row->getKey())->delete();
    })->toThrow(QueryException::class);
});
