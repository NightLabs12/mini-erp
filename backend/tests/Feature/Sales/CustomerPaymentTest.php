<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\Item;
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

    $this->stock->receive($this->item, $this->warehouse, '2880', '11000', ['type' => 'GR', 'id' => 1], $this->date);
});

function approvedSalesInvoiceFor(Item $item): SalesInvoice
{
    $order = test()->salesOrders->create([
        'doc_date' => test()->date,
        'customer_id' => test()->customer->getKey(),
        'warehouse_id' => test()->warehouse->getKey(),
        'items' => [
            [
                'item_id' => $item->getKey(),
                'unit_id' => test()->dus->getKey(),
                'qty' => '5',
                'discount_pct' => '0',
            ],
        ],
    ]);
    test()->salesOrders->approve($order);
    $delivery = test()->deliveries->approve(salesDelivery($order->fresh()));

    return test()->salesInvoices->approve(salesInvoice($delivery->fresh()));
}

it('mengalokasikan pembayaran parsial ke beberapa invoice', function (): void {
    $invoiceOne = approvedSalesInvoiceFor($this->item);

    $itemTwo = masterItem($this->pcs, [
        'sku' => 'KAOS-2',
        'name' => 'Kaos Polos Dua',
        'category_id' => $this->category->getKey(),
    ]);
    addConversion($itemTwo, $this->dus, '144');
    SellingPrice::query()->create([
        'item_id' => $itemTwo->getKey(),
        'unit_id' => $this->dus->getKey(),
        'customer_id' => null,
        'price' => '2160000',
    ]);
    $this->stock->receive($itemTwo, $this->warehouse, '720', '11000', ['type' => 'GR', 'id' => 2], $this->date);
    $invoiceTwo = approvedSalesInvoiceFor($itemTwo);

    $payment = $this->payments->create([
        'doc_date' => $this->date,
        'customer_id' => $this->customer->getKey(),
        'method' => 'bank',
        'amount' => '5000000.00',
        'allocations' => [
            ['sales_invoice_id' => $invoiceOne->getKey(), 'amount' => '3000000.00'],
            ['sales_invoice_id' => $invoiceTwo->getKey(), 'amount' => '2000000.00'],
        ],
    ]);

    $approved = $this->payments->approve($payment);
    $journal = Journal::query()->findOrFail($approved->fresh()->journal_id);

    assertJournalBalanced($journal);

    expect((string) $journal->lines()->where('account_id', accountByCode('1-1200')->getKey())->value('debit'))
        ->toBe('5000000.00')
        ->and((string) $journal->lines()->where('account_id', accountByCode('1-1300')->getKey())->value('credit'))
        ->toBe('5000000.00')
        ->and((string) $invoiceOne->fresh()->paid_amount)->toBe('3000000.00')
        ->and((string) $invoiceTwo->fresh()->paid_amount)->toBe('2000000.00')
        ->and($invoiceOne->fresh()->remaining())->toBe('8988000.00')
        ->and($invoiceTwo->fresh()->remaining())->toBe('9988000.00');
});

it('menolak alokasi yang melebihi sisa tagihan', function (): void {
    $invoice = approvedSalesInvoiceFor($this->item);

    try {
        $this->payments->create([
            'doc_date' => $this->date,
            'customer_id' => $this->customer->getKey(),
            'method' => 'bank',
            'amount' => '20000000.00',
            'allocations' => [
                ['sales_invoice_id' => $invoice->getKey(), 'amount' => '20000000.00'],
            ],
        ]);
        $this->fail('Alokasi melebihi sisa seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PAYMENT_EXCEEDS_REMAINING')
            ->and($exception->status())->toBe(422);
    }
});

it('menolak total pembayaran yang tidak sama dengan total alokasi', function (): void {
    $invoice = approvedSalesInvoiceFor($this->item);

    try {
        $this->payments->create([
            'doc_date' => $this->date,
            'customer_id' => $this->customer->getKey(),
            'method' => 'bank',
            'amount' => '4000000.00',
            'allocations' => [
                ['sales_invoice_id' => $invoice->getKey(), 'amount' => '5000000.00'],
            ],
        ]);
        $this->fail('Pembayaran tidak sama dengan alokasi seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PAYMENT_AMOUNT_MISMATCH');
    }
});

it('menolak alokasi ke invoice yang belum approved', function (): void {
    $order = salesOrder();
    $this->salesOrders->approve($order);
    $delivery = $this->deliveries->approve(salesDelivery($order->fresh()));

    $draft = $this->salesInvoices->create([
        'doc_date' => $this->date,
        'customer_id' => $this->customer->getKey(),
        'items' => [
            [
                'delivery_order_item_id' => $delivery->items()->firstOrFail()->getKey(),
                'qty' => '5',
            ],
        ],
    ]);

    try {
        $this->payments->create([
            'doc_date' => $this->date,
            'customer_id' => $this->customer->getKey(),
            'method' => 'cash',
            'amount' => '1000000.00',
            'allocations' => [
                ['sales_invoice_id' => $draft->getKey(), 'amount' => '1000000.00'],
            ],
        ]);
        $this->fail('Pembayaran ke invoice draft seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PAYMENT_INVOICE_NOT_APPROVED');
    }
});

it('membalik pembayaran saat disapprove dan memakai akun kas untuk metode cash', function (): void {
    $invoice = approvedSalesInvoiceFor($this->item);

    $payment = $this->payments->create([
        'doc_date' => $this->date,
        'customer_id' => $this->customer->getKey(),
        'method' => 'cash',
        'amount' => '1000000.00',
        'allocations' => [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '1000000.00'],
        ],
    ]);

    $approved = $this->payments->approve($payment);
    $journal = Journal::query()->findOrFail($approved->fresh()->journal_id);

    expect((string) $journal->lines()->where('account_id', accountByCode('1-1100')->getKey())->value('debit'))
        ->toBe('1000000.00');

    $draft = $this->payments->disapprove($approved);

    expect($draft->status)->toBe('draft')
        ->and((string) $invoice->fresh()->paid_amount)->toBe('0.00')
        ->and($journal->fresh()->status)->toBe(Journal::STATUS_REVERSED);

    $reversal = Journal::query()->where('reversal_of_id', $journal->getKey())->firstOrFail();
    assertJournalBalanced($reversal);
});
