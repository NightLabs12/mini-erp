<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
use App\Domain\Master\Models\Item;
use App\Domain\Purchasing\Models\PurchaseInvoice;
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
});

function approvedInvoiceFor(Item $item): PurchaseInvoice
{
    $order = test()->orders->create([
        'doc_date' => test()->date,
        'supplier_id' => test()->supplier->getKey(),
        'warehouse_id' => test()->warehouse->getKey(),
        'items' => [
            [
                'item_id' => $item->getKey(),
                'unit_id' => test()->dus->getKey(),
                'qty' => '10',
                'unit_price' => '1440000',
                'discount_pct' => '0',
            ],
        ],
    ]);
    test()->orders->approve($order);
    $receipt = test()->receipts->approve(purchasingReceipt($order->fresh()));
    $grItem = $receipt->items()->firstOrFail();

    return test()->invoices->approve(test()->invoices->create([
        'doc_date' => test()->date,
        'supplier_id' => test()->supplier->getKey(),
        'items' => [
            [
                'goods_receipt_item_id' => $grItem->getKey(),
                'qty' => '10',
                'unit_price' => '1440000',
            ],
        ],
    ]));
}

it('mengalokasikan pembayaran parsial ke beberapa invoice tanpa melebihi sisa', function (): void {
    $invoiceOne = approvedInvoiceFor($this->item);

    $itemTwo = masterItem($this->pcs, [
        'sku' => 'KAOS-2',
        'name' => 'Kaos Polos Dua',
        'category_id' => $this->category->getKey(),
    ]);
    addConversion($itemTwo, $this->dus, '144');
    $invoiceTwo = approvedInvoiceFor($itemTwo);

    $payment = $this->payments->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'method' => 'bank',
        'amount' => '5000000.00',
        'allocations' => [
            ['purchase_invoice_id' => $invoiceOne->getKey(), 'amount' => '3000000.00'],
            ['purchase_invoice_id' => $invoiceTwo->getKey(), 'amount' => '2000000.00'],
        ],
    ]);

    $approved = $this->payments->approve($payment);
    $journal = Journal::query()->findOrFail($approved->fresh()->journal_id);

    assertJournalBalanced($journal);

    expect((string) purchasingJournalLine($journal, '2-1100')->debit)->toBe('5000000.00')
        ->and((string) purchasingJournalLine($journal, '1-1200')->credit)->toBe('5000000.00')
        ->and((string) $invoiceOne->fresh()->paid_amount)->toBe('3000000.00')
        ->and((string) $invoiceTwo->fresh()->paid_amount)->toBe('2000000.00')
        ->and($invoiceOne->fresh()->remaining())->toBe('12984000.00')
        ->and($invoiceTwo->fresh()->remaining())->toBe('13984000.00');
});

it('menolak alokasi yang melebihi sisa tagihan', function (): void {
    $invoice = approvedInvoiceFor($this->item);

    try {
        $this->payments->create([
            'doc_date' => $this->date,
            'supplier_id' => $this->supplier->getKey(),
            'method' => 'bank',
            'amount' => '20000000.00',
            'allocations' => [
                ['purchase_invoice_id' => $invoice->getKey(), 'amount' => '20000000.00'],
            ],
        ]);
        $this->fail('Alokasi melebihi sisa seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PAYMENT_EXCEEDS_REMAINING')
            ->and($exception->status())->toBe(422);
    }
});

it('menolak total pembayaran yang tidak sama dengan total alokasi', function (): void {
    $invoice = approvedInvoiceFor($this->item);

    try {
        $this->payments->create([
            'doc_date' => $this->date,
            'supplier_id' => $this->supplier->getKey(),
            'method' => 'bank',
            'amount' => '4000000.00',
            'allocations' => [
                ['purchase_invoice_id' => $invoice->getKey(), 'amount' => '5000000.00'],
            ],
        ]);
        $this->fail('Pembayaran tidak sama dengan alokasi seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PAYMENT_AMOUNT_MISMATCH');
    }
});

it('menolak alokasi ke invoice yang belum approved', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $receipt = $this->receipts->approve(purchasingReceipt($order->fresh()));
    $grItem = $receipt->items()->firstOrFail();

    $draft = $this->invoices->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'items' => [
            ['goods_receipt_item_id' => $grItem->getKey(), 'qty' => '10', 'unit_price' => '1440000'],
        ],
    ]);

    try {
        $this->payments->create([
            'doc_date' => $this->date,
            'supplier_id' => $this->supplier->getKey(),
            'method' => 'cash',
            'amount' => '1000000.00',
            'allocations' => [
                ['purchase_invoice_id' => $draft->getKey(), 'amount' => '1000000.00'],
            ],
        ]);
        $this->fail('Pembayaran ke invoice draft seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PAYMENT_INVOICE_NOT_APPROVED');
    }
});

it('membalik pembayaran saat disapprove dan memakai akun kas untuk metode cash', function (): void {
    $invoice = approvedInvoiceFor($this->item);

    $payment = $this->payments->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'method' => 'cash',
        'amount' => '1000000.00',
        'allocations' => [
            ['purchase_invoice_id' => $invoice->getKey(), 'amount' => '1000000.00'],
        ],
    ]);

    $approved = $this->payments->approve($payment);
    $journal = Journal::query()->findOrFail($approved->fresh()->journal_id);

    expect((string) purchasingJournalLine($journal, '1-1100')->credit)->toBe('1000000.00');

    $draft = $this->payments->disapprove($approved);

    expect($draft->status)->toBe('draft')
        ->and((string) $invoice->fresh()->paid_amount)->toBe('0.00')
        ->and($journal->fresh()->status)->toBe(Journal::STATUS_REVERSED);

    $reversal = Journal::query()
        ->where('reversal_of_id', $journal->getKey())
        ->firstOrFail();

    assertJournalBalanced($reversal);
});
