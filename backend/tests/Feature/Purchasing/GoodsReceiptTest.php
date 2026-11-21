<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
use App\Domain\Core\Services\SettingService;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Exceptions\ApiException;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);

    $this->pcs = masterUnit('PCS', 'Pcs');
    $this->dus = masterUnit('DUS', 'Dus');
    $this->category = App\Domain\Master\Models\ItemCategory::query()->create([
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

    $this->warehouse = App\Domain\Master\Models\Warehouse::factory()->create(['code' => 'GD-UTM', 'name' => 'Gudang Utama']);
    $this->supplier = App\Domain\Master\Models\Supplier::factory()->create(['code' => 'SUP-1', 'payment_term_days' => 30]);
    $this->date = now()->toDateString();

    $this->orders = app(App\Domain\Purchasing\Services\PurchaseOrderService::class);
    $this->receipts = app(App\Domain\Purchasing\Services\GoodsReceiptService::class);
    $this->invoices = app(App\Domain\Purchasing\Services\PurchaseInvoiceService::class);
    $this->stock = app(App\Domain\Inventory\Services\StockService::class);
});

it('membuat ledger dan jurnal seimbang saat approve GR, serta idempoten', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);

    $receipt = purchasingReceipt($order->fresh());
    $approved = $this->receipts->approve($receipt);

    expect($approved->status)->toBe(GoodsReceipt::STATUS_APPROVED)
        ->and($approved->journal_id)->not->toBeNull();

    $journal = Journal::query()->findOrFail($approved->journal_id);
    assertJournalBalanced($journal);

    $ledgerCount = StockLedger::query()
        ->where('doc_type', 'GR')
        ->where('doc_id', $approved->getKey())
        ->count();

    expect($ledgerCount)->toBe(1);

    $again = $this->receipts->approve($approved);

    expect($again->status)->toBe(GoodsReceipt::STATUS_APPROVED)
        ->and(StockLedger::query()
            ->where('doc_type', 'GR')
            ->where('doc_id', $approved->getKey())
            ->count())->toBe(1)
        ->and(Journal::query()
            ->where('source_type', 'goods_receipt')
            ->where('source_id', $approved->getKey())
            ->count())->toBe(1);
});

it('membalik stok dan jurnal saat disapprove GR', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $receipt = purchasingReceipt($order->fresh());
    $approved = $this->receipts->approve($receipt);
    $journal = Journal::query()->findOrFail($approved->journal_id);

    $draft = $this->receipts->disapprove($approved);

    expect($draft->status)->toBe(GoodsReceipt::STATUS_DRAFT)
        ->and($draft->journal_id)->toBeNull()
        ->and($journal->fresh()->status)->toBe(Journal::STATUS_REVERSED);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('0.0000')
        ->and((string) $balance->value)->toBe('0.00');

    $reversals = StockLedger::query()
        ->where('doc_type', 'GR')
        ->where('doc_id', $approved->getKey())
        ->whereNotNull('reversal_of_id')
        ->get();

    expect($reversals)->toHaveCount(1);

    $order->refresh();

    expect($order->status)->toBe(PurchaseOrder::STATUS_APPROVED)
        ->and((string) $order->items()->firstOrFail()->received_qty_base)->toBe('0.0000');
});

it('menolak disapprove GR yang sudah ditagih', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $receipt = $this->receipts->approve(purchasingReceipt($order->fresh()));

    $invoice = purchasingInvoice($receipt);
    $this->invoices->approve($invoice);

    try {
        $this->receipts->disapprove($receipt->fresh());
        $this->fail('Disapprove GR yang sudah ditagih seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('DOCUMENT_HAS_CHILDREN')
            ->and($exception->status())->toBe(409);
    }
});

it('mengizinkan disapprove GR setelah invoice-nya dibatalkan', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $receipt = $this->receipts->approve(purchasingReceipt($order->fresh()));

    $invoice = purchasingInvoice($receipt);
    $this->invoices->approve($invoice);
    $this->invoices->disapprove($invoice->fresh());

    $draft = $this->receipts->disapprove($receipt->fresh());

    expect($draft->status)->toBe(GoodsReceipt::STATUS_DRAFT);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('0.0000')
        ->and((string) $balance->value)->toBe('0.00');
});

it('menolak disapprove GR bila ada mutasi lanjutan', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $poItem = $order->items()->firstOrFail();

    $first = $this->receipts->create([
        'doc_date' => $this->date,
        'purchase_order_id' => $order->getKey(),
        'items' => [['purchase_order_item_id' => $poItem->getKey(), 'qty' => '4']],
    ]);
    $this->receipts->approve($first);

    $second = $this->receipts->create([
        'doc_date' => $this->date,
        'purchase_order_id' => $order->getKey(),
        'items' => [['purchase_order_item_id' => $poItem->getKey(), 'qty' => '6']],
    ]);
    $this->receipts->approve($second);

    try {
        $this->receipts->disapprove($first->fresh());
        $this->fail('Disapprove GR dengan mutasi lanjutan seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('STOCK_REVERSAL_BLOCKED')
            ->and($exception->status())->toBe(409);
    }
});

it('menolak disapprove GR pada periode closed', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $receipt = $this->receipts->approve(purchasingReceipt($order->fresh()));

    accountingPeriod((int) now()->year, (int) now()->month, 'closed');

    try {
        $this->receipts->disapprove($receipt);
        $this->fail('Disapprove pada periode closed seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_CLOSED')
            ->and($exception->status())->toBe(422);
    }
});

it('mendukung GR parsial dan menutup PO otomatis saat terpenuhi penuh', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $poItem = $order->items()->firstOrFail();

    $first = $this->receipts->create([
        'doc_date' => $this->date,
        'purchase_order_id' => $order->getKey(),
        'items' => [['purchase_order_item_id' => $poItem->getKey(), 'qty' => '4']],
    ]);
    $this->receipts->approve($first);

    $order->refresh();

    expect($order->status)->toBe(PurchaseOrder::STATUS_APPROVED)
        ->and((string) $order->items()->firstOrFail()->received_qty_base)->toBe('576.0000');

    $second = $this->receipts->create([
        'doc_date' => $this->date,
        'purchase_order_id' => $order->getKey(),
        'items' => [['purchase_order_item_id' => $poItem->getKey(), 'qty' => '6']],
    ]);
    $this->receipts->approve($second);

    $order->refresh();

    expect($order->status)->toBe(PurchaseOrder::STATUS_CLOSED)
        ->and($order->closed_manually)->toBeFalse()
        ->and((string) $order->items()->firstOrFail()->received_qty_base)->toBe('1440.0000');
});

it('menolak qty GR melebihi sisa PO', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $poItem = $order->items()->firstOrFail();

    try {
        $this->receipts->create([
            'doc_date' => $this->date,
            'purchase_order_id' => $order->getKey(),
            'items' => [['purchase_order_item_id' => $poItem->getKey(), 'qty' => '11']],
        ]);
        $this->fail('GR melebihi sisa PO seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('GR_QTY_EXCEEDS_REMAINING')
            ->and($exception->status())->toBe(422);
    }
});

it('menghormati toleransi over receipt dari setting', function (): void {
    app(SettingService::class)->set('gr_over_receipt_tolerance_pct', '5');

    $order = purchasingOrder();
    $this->orders->approve($order);
    $poItem = $order->items()->firstOrFail();

    $receipt = $this->receipts->create([
        'doc_date' => $this->date,
        'purchase_order_id' => $order->getKey(),
        'items' => [['purchase_order_item_id' => $poItem->getKey(), 'qty' => '10.5']],
    ]);

    $this->receipts->approve($receipt);

    expect((string) $this->stock->balance($this->item, $this->warehouse)->qty_base)->toBe('1512.0000');
});

it('menutup PO manual dan menolak GR baru', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $closed = $this->orders->close($order);

    expect($closed->status)->toBe(PurchaseOrder::STATUS_CLOSED)
        ->and($closed->closed_manually)->toBeTrue();

    try {
        purchasingReceipt($closed);
        $this->fail('GR dari PO closed seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PURCHASE_ORDER_NOT_APPROVED');
    }
});

it('menolak disapprove PO yang masih punya GR aktif', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    purchasingReceipt($order->fresh());

    try {
        $this->orders->disapprove($order->fresh());
        $this->fail('Disapprove PO dengan GR aktif seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('DOCUMENT_HAS_CHILDREN');
    }
});
