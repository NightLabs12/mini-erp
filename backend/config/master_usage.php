<?php

declare(strict_types=1);

use App\Domain\Master\Models\Customer;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Supplier;
use App\Domain\Master\Models\Unit;
use App\Domain\Master\Models\Warehouse;

/**
 * Referensi yang membuat master tidak boleh dihapus.
 *
 * Tabel yang belum ada dilewati MasterUsageGuard, sehingga entri untuk
 * dokumen pada fase berikutnya sudah bisa disiapkan di sini.
 */
return [
    Unit::class => [
        ['items', 'base_unit_id'],
        ['item_unit_conversions', 'unit_id'],
        ['selling_prices', 'unit_id'],
        ['purchase_order_items', 'unit_id'],
        ['sales_order_items', 'unit_id'],
    ],
    ItemCategory::class => [
        ['items', 'category_id'],
    ],
    Item::class => [
        ['selling_prices', 'item_id'],
        ['purchase_order_items', 'item_id'],
        ['sales_order_items', 'item_id'],
        ['goods_receipt_items', 'item_id'],
        ['delivery_order_items', 'item_id'],
        ['stock_ledger', 'item_id'],
        ['stock_balances', 'item_id'],
    ],
    Warehouse::class => [
        ['purchase_orders', 'warehouse_id'],
        ['sales_orders', 'warehouse_id'],
        ['goods_receipts', 'warehouse_id'],
        ['delivery_orders', 'warehouse_id'],
        ['stock_ledger', 'warehouse_id'],
        ['stock_balances', 'warehouse_id'],
    ],
    Supplier::class => [
        ['purchase_orders', 'supplier_id'],
        ['goods_receipts', 'supplier_id'],
        ['purchase_invoices', 'supplier_id'],
    ],
    Customer::class => [
        ['sales_orders', 'customer_id'],
        ['delivery_orders', 'customer_id'],
        ['sales_invoices', 'customer_id'],
        ['selling_prices', 'customer_id'],
    ],
    SellingPrice::class => [],
];
