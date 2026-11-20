<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
use App\Domain\Inventory\Models\StockLedger;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Sales\Models\DeliveryOrder;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Services\DeliveryOrderService;
use App\Domain\Sales\Services\SalesInvoiceService;
use App\Domain\Sales\Services\SalesOrderService;
use App\Exceptions\ApiException;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);

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
    $this->customer = Customer::factory()->create(['code' => 'CUS-1', 'payment_term_days' => 30, 'credit_limit' => '0.00']);
    $this->date = now()->toDateString();

    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->dus->getKey(),
        'customer_id' => null,
        'price' => '2160000',
    ]);

    $this->stock = app(StockService::class);
    $this->salesOrders = app(SalesOrderService::class);
    $this->deliveries = app(DeliveryOrderService::class);
    $this->salesInvoices = app(SalesInvoiceService::class);

    $this->stock->receive($this->item, $this->warehouse, '1440', '10000', ['type' => 'GR', 'id' => 1], $this->date);
    $this->stock->receive($this->item, $this->warehouse, '1440', '12000', ['type' => 'GR', 'id' => 2], $this->date);

    $this->order = salesOrder();
    $this->salesOrders->approve($this->order);
});

it('menyimpan HPP average dan jurnal Dr HPP / Cr Persediaan', function (): void {
    $delivery = $this->deliveries->approve(salesDelivery($this->order->fresh()));
    $item = $delivery->items()->firstOrFail();

    expect((string) $item->cogs_unit_cost_base)->toBe('11000.0000')
        ->and((string) $item->total_cogs)->toBe('7920000.00');

    $journal = Journal::query()->findOrFail($delivery->journal_id);
    assertJournalBalanced($journal);

    expect((string) $journal->lines()->where('account_id', accountByCode('5-1000')->getKey())->value('debit'))
        ->toBe('7920000.00')
        ->and((string) $journal->lines()->where('account_id', accountByCode('1-1400')->getKey())->value('credit'))
        ->toBe('7920000.00');
});

it('membalik stok dan jurnal saat disapprove DO', function (): void {
    $delivery = $this->deliveries->approve(salesDelivery($this->order->fresh()));
    $journal = Journal::query()->findOrFail($delivery->journal_id);

    $draft = $this->deliveries->disapprove($delivery);

    expect($draft->status)->toBe(DeliveryOrder::STATUS_DRAFT)
        ->and($draft->journal_id)->toBeNull()
        ->and($journal->fresh()->status)->toBe(Journal::STATUS_REVERSED);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('2880.0000')
        ->and((string) $balance->value)->toBe('31680000.00');

    expect(StockLedger::query()
        ->where('doc_type', 'DO')
        ->where('doc_id', $delivery->getKey())
        ->whereNotNull('reversal_of_id')
        ->count())->toBe(1);

    expect((string) $this->order->fresh()->items()->firstOrFail()->delivered_qty_base)->toBe('0.0000')
        ->and($this->order->fresh()->status)->toBe(SalesOrder::STATUS_APPROVED);
});

it('menolak disapprove DO yang sudah ditagih', function (): void {
    $delivery = $this->deliveries->approve(salesDelivery($this->order->fresh()));
    $this->salesInvoices->approve(salesInvoice($delivery->fresh()));

    try {
        $this->deliveries->disapprove($delivery->fresh());
        $this->fail('Disapprove DO yang sudah ditagih seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('DOCUMENT_HAS_CHILDREN')
            ->and($exception->status())->toBe(409);
    }
});

it('menolak disapprove DO pada periode closed', function (): void {
    $delivery = $this->deliveries->approve(salesDelivery($this->order->fresh()));

    accountingPeriod((int) now()->year, (int) now()->month, 'closed');

    try {
        $this->deliveries->disapprove($delivery);
        $this->fail('Disapprove pada periode closed seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_CLOSED')
            ->and($exception->status())->toBe(422);
    }
});

it('mendukung DO parsial dari sisa SO', function (): void {
    $orderItem = $this->order->items()->firstOrFail();

    $first = $this->deliveries->create([
        'doc_date' => $this->date,
        'sales_order_id' => $this->order->getKey(),
        'items' => [['sales_order_item_id' => $orderItem->getKey(), 'qty' => '2']],
    ]);
    $this->deliveries->approve($first);

    expect((string) $this->order->fresh()->items()->firstOrFail()->delivered_qty_base)->toBe('288.0000');

    $second = $this->deliveries->create([
        'doc_date' => $this->date,
        'sales_order_id' => $this->order->getKey(),
        'items' => [['sales_order_item_id' => $orderItem->getKey(), 'qty' => '3']],
    ]);
    $this->deliveries->approve($second);

    expect((string) $this->order->fresh()->items()->firstOrFail()->delivered_qty_base)->toBe('720.0000')
        ->and($this->order->fresh()->status)->toBe(SalesOrder::STATUS_CLOSED);
});

it('menolak DO yang melebihi stok tersedia', function (): void {
    $order = salesOrder([
        'items' => [
            [
                'item_id' => $this->item->getKey(),
                'unit_id' => $this->dus->getKey(),
                'qty' => '30',
                'discount_pct' => '0',
            ],
        ],
    ]);
    $this->salesOrders->approve($order);
    $orderItem = $order->items()->firstOrFail();

    $delivery = $this->deliveries->create([
        'doc_date' => $this->date,
        'sales_order_id' => $order->getKey(),
        'items' => [['sales_order_item_id' => $orderItem->getKey(), 'qty' => '30']],
    ]);

    try {
        $this->deliveries->approve($delivery);
        $this->fail('DO melebihi stok seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('STOCK_INSUFFICIENT');
    }
});
