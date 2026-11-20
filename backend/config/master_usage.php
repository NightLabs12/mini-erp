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
 * Referensi yang membuat master tidak boleh dihapus atau diubah strukturnya.
 *
 * `references` dipakai MasterUsageGuard untuk menolak penghapusan. Entri yang
 * tabelnya belum ada dilewati otomatis, sehingga tabel dokumen fase berikutnya
 * sudah dapat didaftarkan di sini. `document_tables` menandai tabel mana yang
 * dihitung sebagai dokumen: perubahan base_unit_id atau factor_to_base item
 * yang sudah dipakai tabel tersebut ditolak 409 ITEM_IN_USE.
 */
return [
    'references' => [
        Unit::class => [
            ['items', 'base_unit_id'],
            ['item_unit_conversions', 'unit_id'],
            ['selling_prices', 'unit_id'],
            ['purchase_order_items', 'unit_id'],
            ['sales_order_items', 'unit_id'],
            ['warehouse_transfer_items', 'unit_id'],
            ['stock_opname_items', 'unit_id'],
            ['purchase_request_items', 'unit_id'],
            ['purchase_invoice_items', 'unit_id'],
            ['sales_invoice_items', 'unit_id'],
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
            ['warehouse_transfer_items', 'item_id'],
            ['stock_opname_items', 'item_id'],
            ['purchase_request_items', 'item_id'],
            ['purchase_invoice_items', 'item_id'],
            ['sales_invoice_items', 'item_id'],
            ['stock_ledger', 'item_id'],
            ['stock_balances', 'item_id'],
        ],
        Warehouse::class => [
            ['purchase_orders', 'warehouse_id'],
            ['sales_orders', 'warehouse_id'],
            ['goods_receipts', 'warehouse_id'],
            ['delivery_orders', 'warehouse_id'],
            ['warehouse_transfers', 'from_warehouse_id'],
            ['warehouse_transfers', 'to_warehouse_id'],
            ['stock_opnames', 'warehouse_id'],
            ['purchase_requests', 'warehouse_id'],
            ['sales_orders', 'warehouse_id'],
            ['delivery_orders', 'warehouse_id'],
            ['stock_ledger', 'warehouse_id'],
            ['stock_balances', 'warehouse_id'],
        ],
        Supplier::class => [
            ['purchase_orders', 'supplier_id'],
            ['goods_receipts', 'supplier_id'],
            ['purchase_invoices', 'supplier_id'],
            ['purchase_requests', 'supplier_id'],
            ['supplier_payments', 'supplier_id'],
        ],
        Customer::class => [
            ['sales_orders', 'customer_id'],
            ['delivery_orders', 'customer_id'],
            ['sales_invoices', 'customer_id'],
            ['customer_payments', 'customer_id'],
            ['selling_prices', 'customer_id'],
        ],
        SellingPrice::class => [],
    ],

    /**
     * Tabel dokumen/ledger. Selling prices sengaja tidak termasuk karena harga
     * jual bukan dokumen transaksi dan boleh berubah mengikuti master.
     *
     * @var list<string>
     */
    'document_tables' => [
        'purchase_orders',
        'purchase_order_items',
        'sales_orders',
        'sales_order_items',
        'goods_receipts',
        'goods_receipt_items',
        'delivery_orders',
        'delivery_order_items',
        'purchase_invoices',
        'sales_invoices',
        'warehouse_transfers',
        'warehouse_transfer_items',
        'stock_opnames',
        'stock_opname_items',
        'purchase_requests',
        'purchase_request_items',
        'purchase_invoice_items',
        'supplier_payments',
        'sales_invoice_items',
        'customer_payments',
        'stock_ledger',
        'stock_balances',
    ],
];
