<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\CustomerPaymentService;
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

    $this->warehouse = Warehouse::factory()->create(['code' => 'GD-UTM']);
    $this->customer = Customer::factory()->create([
        'code' => 'CUS-1',
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

    $this->stock = app(StockService::class);
    $this->salesOrders = app(SalesOrderService::class);
    $this->deliveries = app(DeliveryOrderService::class);
    $this->salesInvoices = app(SalesInvoiceService::class);
    $this->payments = app(CustomerPaymentService::class);

    $this->stock->receive($this->item, $this->warehouse, '1440', '10000', ['type' => 'GR', 'id' => 1], $this->date);

    $this->order = salesOrder();
    $this->salesOrders->approve($this->order);
    $this->delivery = $this->deliveries->approve(salesDelivery($this->order->fresh()));
});

it('membuat invoice dari DO dengan due date dan jurnal piutang/PPN', function (): void {
    $invoice = $this->salesInvoices->approve(salesInvoice($this->delivery->fresh()));
    $invoice = $invoice->fresh();

    expect($invoice->due_date?->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and((string) $invoice->subtotal)->toBe('10800000.00')
        ->and((string) $invoice->tax_amount)->toBe('1188000.00')
        ->and((string) $invoice->total)->toBe('11988000.00')
        ->and($invoice->mode)->toBe(SalesInvoice::MODE_FINAL);

    $journal = Journal::query()->findOrFail($invoice->journal_id);
    assertJournalBalanced($journal);

    expect((string) $journal->lines()->where('account_id', accountByCode('1-1300')->getKey())->value('debit'))
        ->toBe('11988000.00')
        ->and((string) $journal->lines()->where('account_id', accountByCode('4-1000')->getKey())->value('credit'))
        ->toBe('10800000.00')
        ->and((string) $journal->lines()->where('account_id', accountByCode('2-1300')->getKey())->value('credit'))
        ->toBe('1188000.00');
});

it('mendukung invoice parsial dari sisa DO', function (): void {
    $deliveryItem = $this->delivery->items()->firstOrFail();

    $first = $this->salesInvoices->approve($this->salesInvoices->create([
        'doc_date' => $this->date,
        'customer_id' => $this->customer->getKey(),
        'items' => [['delivery_order_item_id' => $deliveryItem->getKey(), 'qty' => '2']],
    ]));

    expect((string) $first->fresh()->subtotal)->toBe('4320000.00')
        ->and((string) $deliveryItem->fresh()->invoiced_qty_base)->toBe('288.0000');

    $second = $this->salesInvoices->approve($this->salesInvoices->create([
        'doc_date' => $this->date,
        'customer_id' => $this->customer->getKey(),
        'items' => [['delivery_order_item_id' => $deliveryItem->getKey(), 'qty' => '3']],
    ]));

    expect((string) $second->fresh()->subtotal)->toBe('6480000.00')
        ->and((string) $deliveryItem->fresh()->invoiced_qty_base)->toBe('720.0000')
        ->and((string) $this->order->fresh()->items()->firstOrFail()->invoiced_qty_base)->toBe('720.0000');
});

it('mendukung mode final saat pembuatan invoice', function (): void {
    $invoice = salesInvoice($this->delivery->fresh(), ['mode' => 'final']);

    expect($invoice->status)->toBe(SalesInvoice::STATUS_APPROVED)
        ->and($invoice->mode)->toBe(SalesInvoice::MODE_FINAL)
        ->and($invoice->journal_id)->not->toBeNull();
});

it('memposting jurnal saat mode final diminta lewat update', function (): void {
    $deliveryItem = $this->delivery->items()->firstOrFail();

    $draft = $this->salesInvoices->create([
        'doc_date' => $this->date,
        'customer_id' => $this->customer->getKey(),
        'items' => [['delivery_order_item_id' => $deliveryItem->getKey(), 'qty' => '5']],
    ]);

    expect($draft->status)->toBe(SalesInvoice::STATUS_DRAFT)
        ->and($draft->journal_id)->toBeNull();

    $approved = $this->salesInvoices->update($draft, ['mode' => 'final']);

    expect($approved->status)->toBe(SalesInvoice::STATUS_APPROVED)
        ->and($approved->mode)->toBe(SalesInvoice::MODE_FINAL)
        ->and($approved->journal_id)->not->toBeNull();

    $journal = Journal::query()->findOrFail($approved->journal_id);
    assertJournalBalanced($journal);
});

it('menolak qty invoice melebihi sisa DO', function (): void {
    $deliveryItem = $this->delivery->items()->firstOrFail();

    try {
        $this->salesInvoices->create([
            'doc_date' => $this->date,
            'customer_id' => $this->customer->getKey(),
            'items' => [['delivery_order_item_id' => $deliveryItem->getKey(), 'qty' => '6']],
        ]);
        $this->fail('Invoice melebihi sisa DO seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('SI_QTY_EXCEEDS_REMAINING')
            ->and($exception->status())->toBe(422);
    }
});

it('menolak invoice dari DO yang belum approved', function (): void {
    $order = salesOrder([
        'items' => [
            [
                'item_id' => $this->item->getKey(),
                'unit_id' => $this->dus->getKey(),
                'qty' => '10',
                'discount_pct' => '0',
            ],
        ],
    ]);
    $this->salesOrders->approve($order);

    $draft = $this->deliveries->create([
        'doc_date' => $this->date,
        'sales_order_id' => $order->getKey(),
        'items' => [
            ['sales_order_item_id' => $order->items()->firstOrFail()->getKey(), 'qty' => '1'],
        ],
    ]);

    try {
        $this->salesInvoices->create([
            'doc_date' => $this->date,
            'customer_id' => $this->customer->getKey(),
            'items' => [['delivery_order_item_id' => $draft->items()->firstOrFail()->getKey(), 'qty' => '1']],
        ]);
        $this->fail('Invoice dari DO draft seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('DELIVERY_ORDER_NOT_APPROVED');
    }
});

it('menolak disapprove invoice yang sudah dibayar', function (): void {
    $invoice = $this->salesInvoices->approve(salesInvoice($this->delivery->fresh()));

    $payment = $this->payments->create([
        'doc_date' => $this->date,
        'customer_id' => $this->customer->getKey(),
        'method' => 'bank',
        'amount' => '1000000.00',
        'allocations' => [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '1000000.00'],
        ],
    ]);
    $this->payments->approve($payment);

    try {
        $this->salesInvoices->disapprove($invoice->fresh());
        $this->fail('Disapprove invoice yang sudah dibayar seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('DOCUMENT_HAS_PAYMENTS');
    }

    expect($invoice->fresh()->status)->toBe(SalesInvoice::STATUS_APPROVED);
});
