<?php

declare(strict_types=1);

use App\Domain\Purchasing\Services\PurchaseReportService;
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

    $this->warehouse = App\Domain\Master\Models\Warehouse::factory()->create(['code' => 'GD-UTM']);
    $this->supplier = App\Domain\Master\Models\Supplier::factory()->create([
        'code' => 'SUP-1',
        'payment_term_days' => 30,
    ]);
    $this->date = now()->toDateString();

    $this->orders = app(App\Domain\Purchasing\Services\PurchaseOrderService::class);
    $this->receipts = app(App\Domain\Purchasing\Services\GoodsReceiptService::class);
    $this->invoices = app(App\Domain\Purchasing\Services\PurchaseInvoiceService::class);
    $this->payments = app(App\Domain\Purchasing\Services\SupplierPaymentService::class);
    $this->reports = app(PurchaseReportService::class);
});

it('menyajikan PO outstanding, purchase summary, AP outstanding, dan AP aging', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $poItem = $order->items()->firstOrFail();

    $receipt = $this->receipts->approve($this->receipts->create([
        'doc_date' => $this->date,
        'purchase_order_id' => $order->getKey(),
        'items' => [['purchase_order_item_id' => $poItem->getKey(), 'qty' => '4']],
    ]));

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    $outstanding = $this->reports->poOutstanding(null, $from, $to);

    expect($outstanding['totals']['count'])->toBe(1)
        ->and($outstanding['lines'][0]['remaining_qty_base'])->toBe('864.0000')
        ->and($outstanding['lines'][0]['outstanding_value'])->toBe('8640000.00')
        ->and($outstanding['totals']['outstanding_value'])->toBe('8640000.00');

    $grItem = $receipt->items()->firstOrFail();
    $invoice = $this->invoices->approve($this->invoices->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'items' => [
            ['goods_receipt_item_id' => $grItem->getKey(), 'qty' => '4', 'unit_price' => '1440000'],
        ],
    ]));

    $summary = $this->reports->purchaseSummary(null, $from, $to);

    expect($summary['totals']['invoice_count'])->toBe(1)
        ->and($summary['totals']['subtotal'])->toBe('5760000.00')
        ->and($summary['totals']['tax_amount'])->toBe('633600.00')
        ->and($summary['totals']['total'])->toBe('6393600.00');

    $ap = $this->reports->apOutstanding(null, $this->date);

    expect($ap['totals']['count'])->toBe(1)
        ->and($ap['lines'][0]['remaining'])->toBe('6393600.00')
        ->and($ap['lines'][0]['days_overdue'])->toBe(0);

    // Buat satu invoice jatuh tempo 40 hari lalu untuk mengisi bucket 31-60.
    $invoice->forceFill([
        'due_date' => now()->subDays(40)->toDateString(),
    ])->save();

    $aging = $this->reports->apAging(null, $this->date);
    $bucket = collect($aging['buckets'])->firstWhere('label', '31-60 hari');

    expect($bucket)->not->toBeNull()
        ->and($bucket['count'])->toBe(1)
        ->and($bucket['amount'])->toBe('6393600.00')
        ->and($aging['totals']['remaining'])->toBe('6393600.00');
});
