<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\Journal;
use App\Domain\Core\Models\User;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\ItemUnitConversion;
use App\Domain\Master\Models\Unit;
use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Sales\Models\DeliveryOrder;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesOrder;
use App\Support\Math\Decimal;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Menyiapkan 6 role dan seluruh permission sesuai PermissionRegistry.
 */
function seedRbac(): void
{
    test()->seed(RolePermissionSeeder::class);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(string $role, array $attributes = []): User
{
    /** @var User $user */
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Login lewat API dan mengembalikan header Authorization siap pakai.
 *
 * @return array<string, string>
 */
function bearerFor(User $user): array
{
    $token = $user->createToken('test')->plainTextToken;

    return ['Authorization' => 'Bearer '.$token];
}

/**
 * Satuan master dengan kode unik; aman dipanggil berulang dalam satu test.
 */
function masterUnit(string $code, ?string $name = null, int $decimalPlaces = 0): Unit
{
    /** @var Unit $unit */
    $unit = Unit::query()->updateOrCreate(
        ['code' => strtoupper($code)],
        ['name' => $name ?? ucfirst(strtolower($code)), 'decimal_places' => $decimalPlaces],
    );

    return $unit;
}

/**
 * Item lengkap dengan kategori dan satuan base.
 *
 * @param  array<string, mixed>  $attributes
 */
function masterItem(Unit $baseUnit, array $attributes = []): Item
{
    $category = ItemCategory::query()->firstOrCreate(
        ['code' => 'KAT-'.$baseUnit->code],
        ['name' => 'Kategori '.$baseUnit->name, 'item_type' => 'trading'],
    );

    /** @var Item $item */
    $item = Item::query()->create([
        'sku' => 'SKU-'.$baseUnit->code,
        'name' => 'Item '.$baseUnit->name,
        'category_id' => $category->getKey(),
        'base_unit_id' => $baseUnit->getKey(),
        'min_stock' => '0',
        'is_active' => true,
        ...$attributes,
    ]);

    return $item;
}

/**
 * Menambahkan konversi satuan ke item.
 */
function addConversion(Item $item, Unit $unit, string $factor, bool $purchase = false, bool $sales = false): ItemUnitConversion
{
    /** @var ItemUnitConversion $conversion */
    $conversion = $item->conversions()->create([
        'unit_id' => $unit->getKey(),
        'factor_to_base' => $factor,
        'is_purchase_default' => $purchase,
        'is_sales_default' => $sales,
    ]);

    return $conversion;
}

/**
 * Akun COA berdasarkan kode, misalnya 1-1100.
 */
function accountByCode(string $code): Account
{
    /** @var Account $account */
    $account = Account::query()->where('code', $code)->firstOrFail();

    return $account;
}

/**
 * Periode uji; status dapat dipaksa untuk skenario posting ke periode closed.
 */
function accountingPeriod(int $year, int $month, string $status = AccountingPeriod::STATUS_OPEN): AccountingPeriod
{
    /** @var AccountingPeriod $period */
    $period = AccountingPeriod::query()->firstOrCreate(
        ['year' => $year, 'month' => $month],
        ['status' => $status],
    );

    if ($period->status !== $status) {
        $period->update(['status' => $status]);
    }

    return $period->refresh();
}

/**
 * Dua baris jurnal sederhana: debit satu akun, credit akun lain.
 *
 * @return list<array{account_id: int, debit?: string, credit?: string}>
 */
function journalLines(string $debitCode, string $creditCode, string $amount): array
{
    return [
        ['account_id' => accountByCode($debitCode)->getKey(), 'debit' => $amount],
        ['account_id' => accountByCode($creditCode)->getKey(), 'credit' => $amount],
    ];
}

/**
 * Memastikan total baris dan total header jurnal seimbang.
 */
function assertJournalBalanced(Journal $journal): void
{
    $debit = '0.00';
    $credit = '0.00';

    foreach ($journal->lines()->get() as $line) {
        $debit = Decimal::add($debit, (string) $line->debit, Decimal::MONEY_SCALE);
        $credit = Decimal::add($credit, (string) $line->credit, Decimal::MONEY_SCALE);
    }

    expect(Decimal::compare($debit, $credit, Decimal::MONEY_SCALE))->toBe(0)
        ->and(Decimal::compare($debit, (string) $journal->total_debit, Decimal::MONEY_SCALE))->toBe(0)
        ->and(Decimal::compare($credit, (string) $journal->total_credit, Decimal::MONEY_SCALE))->toBe(0);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function purchasingOrder(array $overrides = []): PurchaseOrder
{
    return test()->orders->create([
        'doc_date' => test()->date,
        'supplier_id' => test()->supplier->getKey(),
        'warehouse_id' => test()->warehouse->getKey(),
        'notes' => 'PO uji',
        'items' => [
            [
                'item_id' => test()->item->getKey(),
                'unit_id' => test()->dus->getKey(),
                'qty' => '10',
                'unit_price' => '1440000',
                'discount_pct' => '0',
            ],
        ],
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function purchasingReceipt(PurchaseOrder $order, array $overrides = []): App\Domain\Purchasing\Models\GoodsReceipt
{
    $poItem = $order->items()->firstOrFail();

    return test()->receipts->create([
        'doc_date' => test()->date,
        'purchase_order_id' => $order->getKey(),
        'notes' => 'GR uji',
        'items' => [
            [
                'purchase_order_item_id' => $poItem->getKey(),
                'qty' => (string) $poItem->qty,
            ],
        ],
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function purchasingInvoice(App\Domain\Purchasing\Models\GoodsReceipt $receipt, array $overrides = []): PurchaseInvoice
{
    $grItem = $receipt->items()->with(['item', 'unit'])->firstOrFail();
    $unitPrice = app(App\Domain\Master\Services\UnitConversionService::class)
        ->unitPriceFromBase($grItem->item, $grItem->unit, (string) $grItem->unit_cost_base);

    return test()->invoices->create([
        'doc_date' => test()->date,
        'supplier_id' => $receipt->supplier_id,
        'notes' => 'PI uji',
        'items' => [
            [
                'goods_receipt_item_id' => $grItem->getKey(),
                'qty' => (string) $grItem->qty,
                'unit_price' => $unitPrice,
            ],
        ],
        ...$overrides,
    ]);
}

function purchasingJournalLine(Journal $journal, string $accountCode): App\Domain\Accounting\Models\JournalLine
{
    $accountId = accountByCode($accountCode)->getKey();

    return $journal->lines()->where('account_id', $accountId)->firstOrFail();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function salesOrder(array $overrides = []): SalesOrder
{
    return test()->salesOrders->create([
        'doc_date' => test()->date,
        'customer_id' => test()->customer->getKey(),
        'warehouse_id' => test()->warehouse->getKey(),
        'notes' => 'SO uji',
        'items' => [
            [
                'item_id' => test()->item->getKey(),
                'unit_id' => test()->dus->getKey(),
                'qty' => '5',
                'discount_pct' => '0',
            ],
        ],
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function salesDelivery(SalesOrder $order, array $overrides = []): DeliveryOrder
{
    $orderItem = $order->items()->firstOrFail();

    return test()->deliveries->create([
        'doc_date' => test()->date,
        'sales_order_id' => $order->getKey(),
        'notes' => 'DO uji',
        'items' => [
            [
                'sales_order_item_id' => $orderItem->getKey(),
                'qty' => (string) $orderItem->qty,
            ],
        ],
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function salesInvoice(DeliveryOrder $delivery, array $overrides = []): SalesInvoice
{
    $deliveryItem = $delivery->items()->firstOrFail();

    return test()->salesInvoices->create([
        'doc_date' => test()->date,
        'customer_id' => $delivery->customer_id,
        'notes' => 'SI uji',
        'items' => [
            [
                'delivery_order_item_id' => $deliveryItem->getKey(),
                'qty' => (string) $deliveryItem->qty,
            ],
        ],
        ...$overrides,
    ]);
}
