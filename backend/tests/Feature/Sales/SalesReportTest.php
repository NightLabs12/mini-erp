<?php

declare(strict_types=1);

use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Sales\Services\DeliveryOrderService;
use App\Domain\Sales\Services\SalesInvoiceService;
use App\Domain\Sales\Services\SalesOrderService;
use App\Domain\Sales\Services\SalesReportService;
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
    $this->reports = app(SalesReportService::class);

    $this->stock->receive($this->item, $this->warehouse, '2880', '11000', ['type' => 'GR', 'id' => 1], $this->date);
});

it('menyajikan SO outstanding, sales summary, AR outstanding, dan AR aging', function (): void {
    $order = salesOrder();
    $this->salesOrders->approve($order);
    $orderItem = $order->items()->firstOrFail();

    $delivery = $this->deliveries->create([
        'doc_date' => $this->date,
        'sales_order_id' => $order->getKey(),
        'items' => [['sales_order_item_id' => $orderItem->getKey(), 'qty' => '2']],
    ]);
    $this->deliveries->approve($delivery);

    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    $outstanding = $this->reports->soOutstanding(null, $from, $to);

    expect($outstanding['totals']['count'])->toBe(1)
        ->and($outstanding['lines'][0]['remaining_qty_base'])->toBe('432.0000')
        ->and($outstanding['lines'][0]['outstanding_value'])->toBe('6480000.00')
        ->and($outstanding['totals']['outstanding_value'])->toBe('6480000.00');

    $invoice = $this->salesInvoices->approve(salesInvoice($delivery->fresh()));

    $summary = $this->reports->salesSummary(null, $from, $to);

    expect($summary['totals']['invoice_count'])->toBe(1)
        ->and($summary['totals']['subtotal'])->toBe('4320000.00')
        ->and($summary['totals']['tax_amount'])->toBe('475200.00')
        ->and($summary['totals']['total'])->toBe('4795200.00');

    $ar = $this->reports->arOutstanding(null, $this->date);

    expect($ar['totals']['count'])->toBe(1)
        ->and($ar['lines'][0]['remaining'])->toBe('4795200.00')
        ->and($ar['lines'][0]['days_overdue'])->toBe(0);

    $invoice->forceFill(['due_date' => now()->subDays(40)->toDateString()])->save();

    $aging = $this->reports->arAging(null, $this->date);
    $bucket = collect($aging['buckets'])->firstWhere('label', '31-60 hari');

    expect($bucket)->not->toBeNull()
        ->and($bucket['count'])->toBe(1)
        ->and($bucket['amount'])->toBe('4795200.00')
        ->and($aging['totals']['remaining'])->toBe('4795200.00');
});
