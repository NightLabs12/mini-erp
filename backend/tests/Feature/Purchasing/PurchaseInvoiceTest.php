<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
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

/**
 * @return array{0: App\Domain\Purchasing\Models\GoodsReceipt, 1: App\Domain\Purchasing\Models\GoodsReceiptItem}
 */
function approvedReceipt(): array
{
    $order = purchasingOrder();
    test()->orders->approve($order);
    $receipt = test()->receipts->approve(purchasingReceipt($order->fresh()));

    return [$receipt->fresh()->load('purchaseOrder'), $receipt->items()->firstOrFail()];
}

it('membebaskan GR/IR, menghitung PPN, dan mencatat selisih harga pembelian', function (): void {
    [$receipt, $grItem] = approvedReceipt();

    $invoice = $this->invoices->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'items' => [
            [
                'goods_receipt_item_id' => $grItem->getKey(),
                'qty' => '10',
                'unit_price' => '1584000',
            ],
        ],
    ]);

    $approved = $this->invoices->approve($invoice);
    $approved = $approved->fresh();

    expect((string) $approved->subtotal)->toBe('15840000.00')
        ->and((string) $approved->tax_amount)->toBe('1742400.00')
        ->and((string) $approved->total)->toBe('17582400.00');

    $journal = Journal::query()->findOrFail($approved->journal_id);
    assertJournalBalanced($journal);

    expect((string) purchasingJournalLine($journal, '2-1200')->debit)->toBe('14400000.00')
        ->and((string) purchasingJournalLine($journal, '1-1500')->debit)->toBe('1742400.00')
        ->and((string) purchasingJournalLine($journal, '5-3000')->debit)->toBe('1440000.00')
        ->and((string) purchasingJournalLine($journal, '2-1100')->credit)->toBe('17582400.00');

    expect((string) $grItem->fresh()->invoiced_qty_base)->toBe('1440.0000')
        ->and((string) $receipt->purchaseOrder->items()->firstOrFail()->invoiced_qty_base)->toBe('1440.0000');
});

it('mencatat selisih harga negatif sebagai credit variance', function (): void {
    [$receipt, $grItem] = approvedReceipt();

    $invoice = $this->invoices->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'items' => [
            [
                'goods_receipt_item_id' => $grItem->getKey(),
                'qty' => '10',
                'unit_price' => '1296000',
            ],
        ],
    ]);

    $approved = $this->invoices->approve($invoice);
    $journal = Journal::query()->findOrFail($approved->fresh()->journal_id);

    expect((string) purchasingJournalLine($journal, '2-1200')->debit)->toBe('14400000.00')
        ->and((string) purchasingJournalLine($journal, '5-3000')->credit)->toBe('1440000.00')
        ->and((string) purchasingJournalLine($journal, '2-1100')->credit)->toBe('14385600.00');

    assertJournalBalanced($journal);
});

it('menghitung due date dari payment term supplier', function (): void {
    [$receipt, $grItem] = approvedReceipt();

    $invoice = $this->invoices->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'items' => [
            ['goods_receipt_item_id' => $grItem->getKey(), 'qty' => '10', 'unit_price' => '1440000'],
        ],
    ]);

    expect($invoice->due_date?->toDateString())
        ->toBe(now()->addDays(30)->toDateString());
});

it('mendukung satu invoice untuk beberapa goods receipt', function (): void {
    $order = purchasingOrder();
    $this->orders->approve($order);
    $poItem = $order->items()->firstOrFail();

    $first = $this->receipts->approve($this->receipts->create([
        'doc_date' => $this->date,
        'purchase_order_id' => $order->getKey(),
        'items' => [['purchase_order_item_id' => $poItem->getKey(), 'qty' => '4']],
    ]));
    $second = $this->receipts->approve($this->receipts->create([
        'doc_date' => $this->date,
        'purchase_order_id' => $order->getKey(),
        'items' => [['purchase_order_item_id' => $poItem->getKey(), 'qty' => '6']],
    ]));

    $invoice = $this->invoices->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'items' => [
            [
                'goods_receipt_item_id' => $first->items()->firstOrFail()->getKey(),
                'qty' => '4',
                'unit_price' => '1440000',
            ],
            [
                'goods_receipt_item_id' => $second->items()->firstOrFail()->getKey(),
                'qty' => '6',
                'unit_price' => '1440000',
            ],
        ],
    ]);

    $this->invoices->approve($invoice);

    expect($invoice->items()->count())->toBe(2)
        ->and((string) $invoice->fresh()->subtotal)->toBe('14400000.00');

    expect((string) $first->items()->firstOrFail()->fresh()->invoiced_qty_base)->toBe('576.0000')
        ->and((string) $second->items()->firstOrFail()->fresh()->invoiced_qty_base)->toBe('864.0000');
});

it('menolak qty invoice melebihi sisa goods receipt', function (): void {
    [$receipt, $grItem] = approvedReceipt();

    try {
        $this->invoices->create([
            'doc_date' => $this->date,
            'supplier_id' => $this->supplier->getKey(),
            'items' => [
                [
                    'goods_receipt_item_id' => $grItem->getKey(),
                    'qty' => '11',
                    'unit_price' => '1440000',
                ],
            ],
        ]);
        $this->fail('Invoice melebihi sisa GR seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PI_QTY_EXCEEDS_REMAINING')
            ->and($exception->status())->toBe(422);
    }
});

it('menolak disapprove invoice yang sudah dibayar', function (): void {
    [$receipt, $grItem] = approvedReceipt();

    $invoice = $this->invoices->approve($this->invoices->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'items' => [
            ['goods_receipt_item_id' => $grItem->getKey(), 'qty' => '10', 'unit_price' => '1440000'],
        ],
    ]));

    $payment = $this->payments->create([
        'doc_date' => $this->date,
        'supplier_id' => $this->supplier->getKey(),
        'method' => 'bank',
        'amount' => '1000000.00',
        'allocations' => [
            ['purchase_invoice_id' => $invoice->getKey(), 'amount' => '1000000.00'],
        ],
    ]);
    $this->payments->approve($payment);

    try {
        $this->invoices->disapprove($invoice->fresh());
        $this->fail('Disapprove invoice yang sudah dibayar seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('DOCUMENT_HAS_PAYMENTS');
    }

    expect($invoice->fresh()->status)->toBe(PurchaseInvoice::STATUS_APPROVED);
});
