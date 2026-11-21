<?php

declare(strict_types=1);

use App\Domain\Accounting\Services\ReconciliationService;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);
    $this->reconciliation = app(ReconciliationService::class);
});

it('menghasilkan rekonsiliasi OK pada database tanpa dokumen', function (): void {
    $report = $this->reconciliation->report(now()->toDateString());

    expect($report['ok'])->toBeTrue()
        ->and($report['checks'])->toHaveCount(4);

    foreach ($report['checks'] as $check) {
        expect($check['status'])->toBe('OK')
            ->and($check['difference'])->toBe('0.00');
    }
});

it('mengabaikan pembayaran bertanggal setelah as-of pada rekonsiliasi AP', function (): void {
    $pcs = masterUnit('PCS', 'Pcs');
    $dus = masterUnit('DUS', 'Dus');
    $category = App\Domain\Master\Models\ItemCategory::query()->create([
        'code' => 'KAOS',
        'name' => 'Kaos Jadi',
        'item_type' => 'trading',
    ]);
    $item = masterItem($pcs, [
        'sku' => 'KAOS-POLOS',
        'name' => 'Kaos Polos',
        'category_id' => $category->getKey(),
    ]);
    addConversion($item, $dus, '144');

    $warehouse = App\Domain\Master\Models\Warehouse::factory()->create(['code' => 'GD-UTM']);
    $supplier = App\Domain\Master\Models\Supplier::factory()->create([
        'code' => 'SUP-1',
        'payment_term_days' => 30,
    ]);
    $date = now()->toDateString();

    $this->orders = app(App\Domain\Purchasing\Services\PurchaseOrderService::class);
    $this->receipts = app(App\Domain\Purchasing\Services\GoodsReceiptService::class);
    $this->invoices = app(App\Domain\Purchasing\Services\PurchaseInvoiceService::class);
    $this->payments = app(App\Domain\Purchasing\Services\SupplierPaymentService::class);
    $this->item = $item;
    $this->dus = $dus;
    $this->warehouse = $warehouse;
    $this->supplier = $supplier;
    $this->date = $date;

    $order = purchasingOrder();
    $this->orders->approve($order);
    $receipt = $this->receipts->approve(purchasingReceipt($order->fresh()));
    $invoice = $this->invoices->approve(purchasingInvoice($receipt));

    $nextMonth = now()->addMonthNoOverflow()->startOfMonth();
    accountingPeriod((int) $nextMonth->year, (int) $nextMonth->month);

    $payment = $this->payments->create([
        'doc_date' => $nextMonth->toDateString(),
        'supplier_id' => $supplier->getKey(),
        'method' => 'bank',
        'amount' => (string) $invoice->fresh()->total,
        'allocations' => [
            [
                'purchase_invoice_id' => $invoice->getKey(),
                'amount' => (string) $invoice->fresh()->total,
            ],
        ],
    ]);
    $this->payments->approve($payment);

    $report = $this->reconciliation->report($date);
    $ap = collect($report['checks'])->firstWhere('check', 'accounts_payable');

    expect($ap['status'])->toBe('OK')
        ->and($ap['actual'])->toBe('-15984000.00')
        ->and($ap['expected'])->toBe('-15984000.00')
        ->and($report['ok'])->toBeTrue();
});
