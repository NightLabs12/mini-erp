<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Services\BalanceSheetService;
use App\Domain\Accounting\Services\IncomeStatementService;
use App\Domain\Accounting\Services\PeriodClosingService;
use App\Domain\Accounting\Services\ReconciliationService;
use App\Domain\Accounting\Services\TrialBalanceService;
use App\Domain\Core\Services\SettingService;
use App\Domain\Inventory\Services\StockOpnameService;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Supplier;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Purchasing\Services\GoodsReceiptService;
use App\Domain\Purchasing\Services\PurchaseInvoiceService;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Purchasing\Services\SupplierPaymentService;
use App\Domain\Sales\Services\CustomerPaymentService;
use App\Domain\Sales\Services\DeliveryOrderService;
use App\Domain\Sales\Services\SalesInvoiceService;
use App\Domain\Sales\Services\SalesOrderService;
use App\Exceptions\ApiException;
use Database\Seeders\AccountingSeeder;

/**
 * Satu test besar untuk acceptance SPEC langkah 1-12.
 *
 * Mencakup pembelian, penjualan, HPP, laba kotor, opname, rekonsiliasi,
 * penolakan disapprove/stock berlebih, dan tutup periode.
 */
it('menjalankan siklus penuh acceptance langkah 1 sampai 12', function (): void {
    $this->seed(AccountingSeeder::class);

    $this->pcs = masterUnit('PCS', 'Pcs');
    $this->dus = masterUnit('DUS', 'Dus');
    $category = ItemCategory::query()->create([
        'code' => 'KAOS',
        'name' => 'Kaos Jadi',
        'item_type' => 'trading',
    ]);
    $this->item = masterItem($this->pcs, [
        'sku' => 'KAOS-POLOS',
        'name' => 'Kaos Polos',
        'category_id' => $category->getKey(),
    ]);
    addConversion($this->item, $this->dus, '144');

    $this->warehouse = Warehouse::factory()->create(['code' => 'GD-UTM', 'name' => 'Gudang Utama']);
    $this->supplier = Supplier::factory()->create([
        'code' => 'SUP-1',
        'name' => 'Supplier Satu',
        'payment_term_days' => 30,
    ]);
    $this->customer = Customer::factory()->create([
        'code' => 'CUS-1',
        'name' => 'Customer Satu',
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
    $this->orders = app(PurchaseOrderService::class);
    $this->receipts = app(GoodsReceiptService::class);
    $this->invoices = app(PurchaseInvoiceService::class);
    $this->payments = app(SupplierPaymentService::class);
    $this->salesOrders = app(SalesOrderService::class);
    $this->deliveries = app(DeliveryOrderService::class);
    $this->salesInvoices = app(SalesInvoiceService::class);
    $this->customerPayments = app(CustomerPaymentService::class);

    // Langkah 1: PO-1 dan GR-1.
    $po1 = purchasingOrder();
    $this->orders->approve($po1);
    $gr1 = purchasingReceipt($po1->fresh());
    $this->receipts->approve($gr1);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('1440.0000')
        ->and((string) $balance->value)->toBe('14400000.00');

    $grJournal = Journal::query()->findOrFail($gr1->fresh()->journal_id);

    expect((string) purchasingJournalLine($grJournal, '1-1400')->debit)->toBe('14400000.00')
        ->and((string) purchasingJournalLine($grJournal, '2-1200')->credit)->toBe('14400000.00');

    // Langkah 2: PI-1.
    $pi1 = $this->invoices->approve(purchasingInvoice($gr1->fresh()));
    $pi1 = $pi1->fresh();

    expect((string) $pi1->subtotal)->toBe('14400000.00')
        ->and((string) $pi1->tax_amount)->toBe('1584000.00')
        ->and((string) $pi1->total)->toBe('15984000.00');

    // Langkah 3: Payment Out penuh.
    $paymentOut = $this->payments->approve($this->payments->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'method' => 'bank',
        'amount' => '15984000.00',
        'allocations' => [
            ['purchase_invoice_id' => $pi1->getKey(), 'amount' => '15984000.00'],
        ],
    ]));

    expect((string) $pi1->fresh()->paid_amount)->toBe('15984000.00');

    $paymentOutJournal = Journal::query()->findOrFail($paymentOut->fresh()->journal_id);

    expect((string) purchasingJournalLine($paymentOutJournal, '2-1100')->debit)->toBe('15984000.00')
        ->and((string) purchasingJournalLine($paymentOutJournal, '1-1200')->credit)->toBe('15984000.00');

    // Langkah 4: PO-2 dan GR-2.
    $po2 = purchasingOrder([
        'items' => [
            [
                'item_id' => $this->item->getKey(),
                'unit_id' => $this->dus->getKey(),
                'qty' => '10',
                'unit_price' => '1728000',
                'discount_pct' => '0',
            ],
        ],
    ]);
    $this->orders->approve($po2);
    $gr2 = purchasingReceipt($po2->fresh());
    $this->receipts->approve($gr2);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('2880.0000')
        ->and((string) $balance->value)->toBe('31680000.00')
        ->and((string) $balance->avg_cost)->toBe('11000.0000')
        ->and($po2->fresh()->status)->toBe('closed');

    // Langkah 5: SO-1 dan DO-1.
    $so1 = salesOrder();
    $this->salesOrders->approve($so1);
    $do1 = $this->deliveries->approve(salesDelivery($so1->fresh()));

    $deliveryItem = $do1->items()->firstOrFail();

    expect((string) $deliveryItem->cogs_unit_cost_base)->toBe('11000.0000')
        ->and((string) $deliveryItem->total_cogs)->toBe('7920000.00');

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('2160.0000')
        ->and((string) $balance->value)->toBe('23760000.00');

    $doJournal = Journal::query()->findOrFail($do1->journal_id);

    expect((string) purchasingJournalLine($doJournal, '5-1000')->debit)->toBe('7920000.00')
        ->and((string) purchasingJournalLine($doJournal, '1-1400')->credit)->toBe('7920000.00');

    // Langkah 6: SI-1.
    $si1 = $this->salesInvoices->approve(salesInvoice($do1->fresh()));
    $si1 = $si1->fresh();

    expect((string) $si1->subtotal)->toBe('10800000.00')
        ->and((string) $si1->tax_amount)->toBe('1188000.00')
        ->and((string) $si1->total)->toBe('11988000.00');

    $siJournal = Journal::query()->findOrFail($si1->journal_id);

    expect((string) purchasingJournalLine($siJournal, '1-1300')->debit)->toBe('11988000.00')
        ->and((string) purchasingJournalLine($siJournal, '4-1000')->credit)->toBe('10800000.00')
        ->and((string) purchasingJournalLine($siJournal, '2-1300')->credit)->toBe('1188000.00');

    // Langkah 7: Payment In penuh.
    $paymentIn = $this->customerPayments->approve($this->customerPayments->create([
        'doc_date' => $this->date,
        'customer_id' => $this->customer->getKey(),
        'method' => 'bank',
        'amount' => '11988000.00',
        'allocations' => [
            ['sales_invoice_id' => $si1->getKey(), 'amount' => '11988000.00'],
        ],
    ]));

    expect((string) $si1->fresh()->paid_amount)->toBe('11988000.00');

    $paymentInJournal = Journal::query()->findOrFail($paymentIn->fresh()->journal_id);

    expect((string) purchasingJournalLine($paymentInJournal, '1-1200')->debit)->toBe('11988000.00')
        ->and((string) purchasingJournalLine($paymentInJournal, '1-1300')->credit)->toBe('11988000.00');

    // Langkah 8: laba kotor 2.880.000 sebelum opname.
    $income = app(IncomeStatementService::class)->report($this->date, $this->date);

    expect($income['gross_profit'])->toBe('2880000.00');

    // Langkah 9: stock opname fisik 2.150 pcs (selisih -10).
    $opname = app(StockOpnameService::class)->create([
        'doc_date' => $this->date,
        'warehouse_id' => $this->warehouse->getKey(),
        'notes' => 'Opname akhir siklus',
        'items' => [
            [
                'item_id' => $this->item->getKey(),
                'unit_id' => $this->pcs->getKey(),
                'counted_qty' => '2150',
            ],
        ],
    ]);
    $opname = app(StockOpnameService::class)->approve($opname);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('2150.0000')
        ->and((string) $balance->value)->toBe('23650000.00');

    $opnameJournal = Journal::query()->findOrFail($opname->journal_id);

    expect((string) purchasingJournalLine($opnameJournal, '5-2000')->debit)->toBe('110000.00')
        ->and((string) purchasingJournalLine($opnameJournal, '1-1400')->credit)->toBe('110000.00');

    // Langkah 10: rekonsiliasi OK.
    $reconciliation = app(ReconciliationService::class)->report($this->date);

    expect($reconciliation['ok'])->toBeTrue();

    $checks = collect($reconciliation['checks'])->keyBy('check');

    expect($checks['accounts_payable']['status'])->toBe('OK')
        ->and($checks['accounts_payable']['actual'])->toBe('0.00')
        ->and($checks['accounts_receivable']['status'])->toBe('OK')
        ->and($checks['accounts_receivable']['actual'])->toBe('0.00')
        ->and($checks['inventory']['status'])->toBe('OK')
        ->and($checks['inventory']['actual'])->toBe('23650000.00')
        ->and($checks['grir']['status'])->toBe('OK')
        ->and($checks['grir']['actual'])->toBe('-17280000.00');

    // Neraca seimbang dan Trial Balance seimbang.
    $balanceSheet = app(BalanceSheetService::class)->report($this->date);
    $trialBalance = app(TrialBalanceService::class)->report($this->date, $this->date);

    expect($balanceSheet['balanced'])->toBeTrue()
        ->and($trialBalance['balanced'])->toBeTrue();

    // Langkah 11: disapprove GR-1 ditolak, DO melebihi stok ditolak.
    try {
        $this->receipts->disapprove($gr1->fresh());
        $this->fail('Disapprove GR-1 seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->status())->toBe(409);
    }

    $so2 = salesOrder([
        'items' => [
            [
                'item_id' => $this->item->getKey(),
                'unit_id' => $this->dus->getKey(),
                'qty' => '20',
                'discount_pct' => '0',
            ],
        ],
    ]);
    $this->salesOrders->approve($so2);
    $do2 = salesDelivery($so2->fresh());

    try {
        $this->deliveries->approve($do2);
        $this->fail('DO melebihi stok seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('STOCK_INSUFFICIENT');
    }

    // Langkah 12: tutup periode, lalu approve dokumen bertanggal periode itu ditolak.
    app(SettingService::class)->set('require_no_draft_on_close', 'false');

    $draftPo = purchasingOrder([
        'items' => [
            [
                'item_id' => $this->item->getKey(),
                'unit_id' => $this->dus->getKey(),
                'qty' => '1',
                'unit_price' => '1440000',
                'discount_pct' => '0',
            ],
        ],
    ]);

    $closing = app(PeriodClosingService::class);
    $year = (int) now()->year;
    $currentMonth = (int) now()->month;

    for ($month = 1; $month < $currentMonth; $month++) {
        $closing->close(accountingPeriod($year, $month));
    }

    $closed = $closing->close(accountingPeriod($year, $currentMonth));

    expect($closed->status)->toBe(AccountingPeriod::STATUS_CLOSED);

    try {
        $this->orders->approve($draftPo);
        $this->fail('Approve dokumen pada periode closed seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_CLOSED');
    }
});
