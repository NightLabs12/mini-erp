<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Supplier;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Services\GoodsReceiptService;
use App\Domain\Purchasing\Services\PurchaseInvoiceService;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Domain\Purchasing\Services\PurchaseRequestService;
use App\Domain\Purchasing\Services\SupplierPaymentService;
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
    $this->supplier = Supplier::factory()->create([
        'code' => 'SUP-1',
        'name' => 'Supplier Satu',
        'payment_term_days' => 30,
    ]);
    $this->date = now()->toDateString();

    $this->orders = app(PurchaseOrderService::class);
    $this->receipts = app(GoodsReceiptService::class);
    $this->invoices = app(PurchaseInvoiceService::class);
    $this->payments = app(SupplierPaymentService::class);
    $this->stock = app(StockService::class);
});

it('menjalankan skenario acceptance langkah 1 sampai 4', function (): void {
    // Langkah 1: PO-1 10 Dus @ Rp1.440.000/Dus, GR-1 10 Dus.
    $po1 = purchasingOrder();
    $this->orders->approve($po1);

    expect($po1->fresh()->status)->toBe(PurchaseOrder::STATUS_APPROVED)
        ->and((string) $po1->fresh()->total)->toBe('15984000.00');

    $gr1 = purchasingReceipt($po1->fresh());
    $this->receipts->approve($gr1);

    $balance = $this->stock->balance($this->item, $this->warehouse);

    expect((string) $balance->qty_base)->toBe('1440.0000')
        ->and((string) $balance->value)->toBe('14400000.00')
        ->and((string) $balance->avg_cost)->toBe('10000.0000');

    $grJournal = Journal::query()->findOrFail($gr1->fresh()->journal_id);
    assertJournalBalanced($grJournal);

    expect((string) purchasingJournalLine($grJournal, '1-1400')->debit)->toBe('14400000.00')
        ->and((string) purchasingJournalLine($grJournal, '2-1200')->credit)->toBe('14400000.00');

    // Langkah 2: PI-1 untuk GR-1.
    $pi1 = purchasingInvoice($gr1->fresh());
    $this->invoices->approve($pi1);

    $pi1 = $pi1->fresh();
    $piJournal = Journal::query()->findOrFail($pi1->journal_id);
    assertJournalBalanced($piJournal);

    expect((string) $pi1->subtotal)->toBe('14400000.00')
        ->and((string) $pi1->tax_amount)->toBe('1584000.00')
        ->and((string) $pi1->total)->toBe('15984000.00')
        ->and((string) $piJournal->lines()->where('account_id', accountByCode('2-1200')->getKey())->value('debit'))
        ->toBe('14400000.00')
        ->and((string) $piJournal->lines()->where('account_id', accountByCode('1-1500')->getKey())->value('debit'))
        ->toBe('1584000.00')
        ->and((string) $piJournal->lines()->where('account_id', accountByCode('2-1100')->getKey())->value('credit'))
        ->toBe('15984000.00');

    // Langkah 3: Payment Out 15.984.000 via Bank.
    $payment = $this->payments->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'method' => 'bank',
        'amount' => '15984000.00',
        'allocations' => [
            ['purchase_invoice_id' => $pi1->getKey(), 'amount' => '15984000.00'],
        ],
    ]);
    $this->payments->approve($payment);

    $payment = $payment->fresh();
    $paymentJournal = Journal::query()->findOrFail($payment->journal_id);
    assertJournalBalanced($paymentJournal);

    expect((string) $pi1->fresh()->paid_amount)->toBe('15984000.00')
        ->and((string) $paymentJournal->lines()->where('account_id', accountByCode('2-1100')->getKey())->value('debit'))
        ->toBe('15984000.00')
        ->and((string) $paymentJournal->lines()->where('account_id', accountByCode('1-1200')->getKey())->value('credit'))
        ->toBe('15984000.00');

    // Langkah 4: PO-2 10 Dus @ Rp1.728.000/Dus, GR-2.
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
        ->and((string) $balance->avg_cost)->toBe('11000.0000');

    expect($po2->fresh()->status)->toBe(PurchaseOrder::STATUS_CLOSED);
});

it('mengonversi purchase request menjadi purchase order dengan menyalin baris', function (): void {
    $requests = app(PurchaseRequestService::class);

    $request = $requests->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'warehouse_id' => $this->warehouse->getKey(),
        'items' => [
            ['item_id' => $this->item->getKey(), 'unit_id' => $this->dus->getKey(), 'qty' => '5'],
        ],
    ]);
    $requests->approve($request);

    $order = $requests->convertToOrder($request, [
        'doc_date' => $this->date,
        'items' => [
            [
                'item_id' => $this->item->getKey(),
                'unit_id' => $this->dus->getKey(),
                'qty' => '5',
                'unit_price' => '1440000',
                'discount_pct' => '0',
            ],
        ],
    ]);

    expect($order->purchase_request_id)->toBe($request->getKey())
        ->and($order->items()->count())->toBe(1)
        ->and((string) $order->items()->firstOrFail()->qty_base)->toBe('720.0000')
        ->and($request->fresh()->status)->toBe('closed');
});
