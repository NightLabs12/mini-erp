<?php

declare(strict_types=1);

use App\Domain\Core\Services\SettingService;
use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Warehouse;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Services\SalesOrderService;
use App\Exceptions\ApiException;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    seedRbac();
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

    $this->salesOrders = app(SalesOrderService::class);
});

it('mengambil harga jual otomatis dari selling_prices', function (): void {
    $order = salesOrder();

    $item = $order->items()->firstOrFail();

    expect((string) $item->unit_price)->toBe('2160000.0000')
        ->and((string) $item->unit_price_base)->toBe('15000.0000')
        ->and((string) $item->line_total)->toBe('10800000.00')
        ->and((string) $order->total)->toBe('11988000.00');
});

it('mengizinkan override harga dengan permission khusus', function (): void {
    $this->actingAs(userWithRole('sales'));

    $order = salesOrder([
        'items' => [
            [
                'item_id' => $this->item->getKey(),
                'unit_id' => $this->dus->getKey(),
                'qty' => '5',
                'unit_price' => '2000000',
                'discount_pct' => '0',
            ],
        ],
    ]);

    expect((string) $order->items()->firstOrFail()->unit_price)->toBe('2000000.0000');
});

it('menolak override harga tanpa permission khusus', function (): void {
    $user = userWithRole('viewer');
    $user->givePermissionTo(['sales.sales-order.create', 'sales.sales-order.view']);
    $this->actingAs($user->fresh());

    try {
        salesOrder([
            'items' => [
                [
                    'item_id' => $this->item->getKey(),
                    'unit_id' => $this->dus->getKey(),
                    'qty' => '5',
                    'unit_price' => '2000000',
                    'discount_pct' => '0',
                ],
            ],
        ]);
        $this->fail('Override harga tanpa permission seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PRICE_OVERRIDE_FORBIDDEN')
            ->and($exception->status())->toBe(422);
    }
});

it('menolak item tanpa harga jual bila harga tidak diisi', function (): void {
    $other = masterItem($this->pcs, [
        'sku' => 'KAOS-2',
        'name' => 'Kaos Tanpa Harga',
        'category_id' => $this->category->getKey(),
    ]);
    addConversion($other, $this->dus, '144');

    try {
        salesOrder([
            'items' => [
                [
                    'item_id' => $other->getKey(),
                    'unit_id' => $this->dus->getKey(),
                    'qty' => '1',
                    'discount_pct' => '0',
                ],
            ],
        ]);
        $this->fail('Item tanpa harga jual seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('SELLING_PRICE_NOT_FOUND')
            ->and($exception->status())->toBe(422);
    }
});

it('memberi peringatan credit limit saat mode warn', function (): void {
    $this->customer->update(['credit_limit' => '1000000']);
    app(SettingService::class)->set('credit_limit_enforcement', 'warn');

    $order = salesOrder();

    expect($order->creditWarning)->not->toBeNull()
        ->and($order->creditWarning)->toContain('Credit limit');
});

it('menolak sales order saat credit limit terlampaui pada mode block', function (): void {
    $this->customer->update(['credit_limit' => '1000000']);
    app(SettingService::class)->set('credit_limit_enforcement', 'block');

    try {
        salesOrder();
        $this->fail('Credit limit terlampaui seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('CREDIT_LIMIT_EXCEEDED')
            ->and($exception->status())->toBe(422);
    }
});

it('tidak memeriksa credit limit saat mode off atau limit nol', function (): void {
    $this->customer->update(['credit_limit' => '1000000']);
    app(SettingService::class)->set('credit_limit_enforcement', 'off');

    expect(salesOrder()->creditWarning)->toBeNull();

    $this->customer->update(['credit_limit' => '0']);
    app(SettingService::class)->set('credit_limit_enforcement', 'block');

    expect(salesOrder()->creditWarning)->toBeNull();
});

it('mendukung pengiriman parsial dan menutup SO saat terpenuhi', function (): void {
    app(App\Domain\Inventory\Services\StockService::class)->receive(
        $this->item,
        $this->warehouse,
        '1000',
        '10000',
        ['type' => 'GR', 'id' => 1],
        $this->date,
    );

    $order = salesOrder();
    $this->salesOrders->approve($order);
    $orderItem = $order->items()->firstOrFail();

    $deliveries = app(App\Domain\Sales\Services\DeliveryOrderService::class);

    $first = $deliveries->create([
        'doc_date' => $this->date,
        'sales_order_id' => $order->getKey(),
        'items' => [['sales_order_item_id' => $orderItem->getKey(), 'qty' => '2']],
    ]);
    $deliveries->approve($first);

    $order->refresh();

    expect($order->status)->toBe(SalesOrder::STATUS_APPROVED)
        ->and((string) $order->items()->firstOrFail()->delivered_qty_base)->toBe('288.0000');

    $second = $deliveries->create([
        'doc_date' => $this->date,
        'sales_order_id' => $order->getKey(),
        'items' => [['sales_order_item_id' => $orderItem->getKey(), 'qty' => '3']],
    ]);
    $deliveries->approve($second);

    expect($order->fresh()->status)->toBe(SalesOrder::STATUS_CLOSED);
});
