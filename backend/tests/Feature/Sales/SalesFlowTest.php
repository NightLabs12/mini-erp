<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Services\IncomeStatementService;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Services\CustomerPaymentService;
use App\Domain\Sales\Services\DeliveryOrderService;
use App\Domain\Sales\Services\SalesInvoiceService;
use App\Domain\Sales\Services\SalesOrderService;
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
    $this->customer = Customer::factory()->create([
        'code' => 'CUS-1',
        'name' => 'Customer Satu',
        'payment_term_days' => 30,
        'credit_limit' => '0.00',
    ]);

    SellingPrice::query()->create([
        'item_id' => $this->item->getKey(),
        'unit_id' => $this->dus->getKey(),
        'customer_id' => null,
        'price' => '2160000',
    ]);

    $this->date = now()->toDateString();

    $this->stock = app(StockService::class);
    $this->salesOrders = app(SalesOrderService::class);
    $this->deliveries = app(DeliveryOrderService::class);
    $this->salesInvoices = app(SalesInvoiceService::class);
    $this->payments = app(CustomerPaymentService::class);

    // Saldo awal 2.880 pcs dengan average 11.000.
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
});

function salesJournalLine(Journal $journal, string $accountCode): App\Domain\Accounting\Models\JournalLine
{
    return $journal->lines()
        ->where('account_id', accountByCode($accountCode)->getKey())
        ->firstOrFail();
}

it('menjalankan skenario acceptance langkah 5 sampai 8', function (): void {
    // Langkah 5: SO-1 5 Dus (720 pcs) @ Rp15.000/pcs, DO-1 HPP 7.920.000.
    $order = salesOrder();
    $this->salesOrders->approve($order);

    expect($order->fresh()->status)->toBe(SalesOrder::STATUS_APPROVED)
        ->and((string) $order->fresh()->total)->toBe('11988000.00');

    $delivery = salesDelivery($order->fresh());
    $approved = $this->deliveries->approve($delivery);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('2160.0000')
        ->and((string) $balance->value)->toBe('23760000.00')
        ->and((string) $balance->avg_cost)->toBe('11000.0000');

    $deliveryItem = $approved->items()->firstOrFail();

    expect((string) $deliveryItem->cogs_unit_cost_base)->toBe('11000.0000')
        ->and((string) $deliveryItem->total_cogs)->toBe('7920000.00');

    $deliveryJournal = Journal::query()->findOrFail($approved->journal_id);
    assertJournalBalanced($deliveryJournal);

    expect((string) salesJournalLine($deliveryJournal, '5-1000')->debit)->toBe('7920000.00')
        ->and((string) salesJournalLine($deliveryJournal, '1-1400')->credit)->toBe('7920000.00');

    // Langkah 6: SI-1 subtotal 10.800.000, PPN 1.188.000, total 11.988.000.
    $invoice = $this->salesInvoices->approve(salesInvoice($approved->fresh()));
    $invoice = $invoice->fresh();

    expect((string) $invoice->subtotal)->toBe('10800000.00')
        ->and((string) $invoice->tax_amount)->toBe('1188000.00')
        ->and((string) $invoice->total)->toBe('11988000.00')
        ->and($invoice->mode)->toBe(SalesInvoice::MODE_FINAL);

    $invoiceJournal = Journal::query()->findOrFail($invoice->journal_id);
    assertJournalBalanced($invoiceJournal);

    expect((string) salesJournalLine($invoiceJournal, '1-1300')->debit)->toBe('11988000.00')
        ->and((string) salesJournalLine($invoiceJournal, '4-1000')->credit)->toBe('10800000.00')
        ->and((string) salesJournalLine($invoiceJournal, '2-1300')->credit)->toBe('1188000.00');

    // Langkah 7: Payment In 11.988.000 via Bank.
    $payment = $this->payments->create([
        'doc_date' => $this->date,
        'customer_id' => $this->customer->getKey(),
        'method' => 'bank',
        'amount' => '11988000.00',
        'allocations' => [
            ['sales_invoice_id' => $invoice->getKey(), 'amount' => '11988000.00'],
        ],
    ]);
    $paid = $this->payments->approve($payment);

    expect((string) $invoice->fresh()->paid_amount)->toBe('11988000.00')
        ->and($invoice->fresh()->remaining())->toBe('0.00');

    $paymentJournal = Journal::query()->findOrFail($paid->fresh()->journal_id);
    assertJournalBalanced($paymentJournal);

    expect((string) salesJournalLine($paymentJournal, '1-1200')->debit)->toBe('11988000.00')
        ->and((string) salesJournalLine($paymentJournal, '1-1300')->credit)->toBe('11988000.00');

    // Langkah 8: laba kotor 2.880.000.
    $income = app(IncomeStatementService::class)->report($this->date, $this->date);

    expect($income['revenue']['total'])->toBe('10800000.00')
        ->and($income['cogs']['total'])->toBe('7920000.00')
        ->and($income['gross_profit'])->toBe('2880000.00')
        ->and($income['net_profit'])->toBe('2880000.00');
});

it('menutup SO otomatis saat seluruh qty dikirim', function (): void {
    $order = salesOrder();
    $this->salesOrders->approve($order);
    $delivery = salesDelivery($order->fresh());

    expect($order->fresh()->status)->toBe(SalesOrder::STATUS_APPROVED);

    $this->deliveries->approve($delivery);

    expect($order->fresh()->status)->toBe(SalesOrder::STATUS_CLOSED)
        ->and($order->fresh()->closed_manually)->toBeFalse()
        ->and((string) $order->fresh()->items()->firstOrFail()->delivered_qty_base)->toBe('720.0000');
});

it('menutup SO manual dan menolak DO baru', function (): void {
    $order = salesOrder();
    $this->salesOrders->approve($order);
    $closed = $this->salesOrders->close($order);

    expect($closed->status)->toBe(SalesOrder::STATUS_CLOSED)
        ->and($closed->closed_manually)->toBeTrue();

    expect(fn () => salesDelivery($closed))
        ->toThrow(App\Exceptions\ApiException::class);
});

it('menolak DO yang melebihi sisa SO', function (): void {
    $order = salesOrder();
    $this->salesOrders->approve($order);
    $orderItem = $order->items()->firstOrFail();

    expect(fn () => $this->deliveries->create([
        'doc_date' => $this->date,
        'sales_order_id' => $order->getKey(),
        'items' => [
            ['sales_order_item_id' => $orderItem->getKey(), 'qty' => '6'],
        ],
    ]))->toThrow(App\Exceptions\ApiException::class);
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
        'items' => [
            ['sales_order_item_id' => $orderItem->getKey(), 'qty' => '30'],
        ],
    ]);

    try {
        $this->deliveries->approve($delivery);
        $this->fail('DO melebihi stok seharusnya ditolak.');
    } catch (App\Exceptions\ApiException $exception) {
        expect($exception->errorCode())->toBe('STOCK_INSUFFICIENT');
    }
});
