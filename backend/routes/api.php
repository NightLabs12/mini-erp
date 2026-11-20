<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountingPeriodController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerPaymentController;
use App\Http\Controllers\Api\V1\DeliveryOrderController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ItemCategoryController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemStockController;
use App\Http\Controllers\Api\V1\ItemUnitConversionController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\PurchaseInvoiceController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseRequestController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SalesInvoiceController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\SellingPriceController;
use App\Http\Controllers\Api\V1\StockBalanceController;
use App\Http\Controllers\Api\V1\StockLedgerController;
use App\Http\Controllers\Api\V1\StockOpnameController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierPaymentController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WarehouseTransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');

    Route::post('auth/login', LoginController::class)
        ->middleware('throttle:login')
        ->name('api.v1.auth.login');

    Route::middleware(['auth:sanctum', 'active', 'demo.readonly'])->group(function (): void {
        Route::post('auth/logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::get('auth/me', MeController::class)->name('api.v1.auth.me');

        Route::get('users/{user}/audit', [UserController::class, 'audit'])->name('api.v1.users.audit');
        Route::apiResource('users', UserController::class);

        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:core.audit.view')
            ->name('api.v1.audit-logs.index');

        // Master data.
        Route::get('units/{unit}/audit', [UnitController::class, 'audit'])->name('api.v1.units.audit');
        Route::apiResource('units', UnitController::class);

        Route::get('item-categories/{itemCategory}/audit', [ItemCategoryController::class, 'audit'])
            ->name('api.v1.item-categories.audit');
        Route::apiResource('item-categories', ItemCategoryController::class)
            ->parameters(['item-categories' => 'itemCategory']);

        Route::get('items/{item}/unit-conversions', [ItemUnitConversionController::class, 'index'])
            ->name('api.v1.items.unit-conversions.index');
        Route::post('items/{item}/unit-conversions', [ItemUnitConversionController::class, 'store'])
            ->name('api.v1.items.unit-conversions.store');
        Route::put('items/{item}/unit-conversions/{conversion}', [ItemUnitConversionController::class, 'update'])
            ->name('api.v1.items.unit-conversions.update');
        Route::delete('items/{item}/unit-conversions/{conversion}', [ItemUnitConversionController::class, 'destroy'])
            ->name('api.v1.items.unit-conversions.destroy');

        Route::get('items/{item}/stock', [ItemStockController::class, 'show'])
            ->middleware('permission:inventory.stock.view')
            ->name('api.v1.items.stock');
        Route::get('items/{item}/audit', [ItemController::class, 'audit'])->name('api.v1.items.audit');
        Route::apiResource('items', ItemController::class);

        Route::get('warehouses/{warehouse}/audit', [WarehouseController::class, 'audit'])
            ->name('api.v1.warehouses.audit');
        Route::apiResource('warehouses', WarehouseController::class);

        Route::get('suppliers/{supplier}/audit', [SupplierController::class, 'audit'])
            ->name('api.v1.suppliers.audit');
        Route::apiResource('suppliers', SupplierController::class);

        Route::get('customers/{customer}/audit', [CustomerController::class, 'audit'])
            ->name('api.v1.customers.audit');
        Route::apiResource('customers', CustomerController::class);

        Route::get('selling-prices/lookup', [SellingPriceController::class, 'lookup'])
            ->name('api.v1.selling-prices.lookup');
        Route::get('selling-prices/{sellingPrice}/audit', [SellingPriceController::class, 'audit'])
            ->name('api.v1.selling-prices.audit');
        Route::apiResource('selling-prices', SellingPriceController::class)
            ->parameters(['selling-prices' => 'sellingPrice']);

        // Akuntansi: jurnal manual dan periode.
        Route::get('journals/{journal}/audit', [JournalController::class, 'audit'])->name('api.v1.journals.audit');
        Route::post('journals/{journal}/approve', [JournalController::class, 'approve'])
            ->name('api.v1.journals.approve');
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])
            ->name('api.v1.journals.reverse');
        Route::apiResource('journals', JournalController::class);

        // Purchasing: PR -> PO -> GR -> PI -> Payment Out.
        Route::get('purchase-requests/{purchaseRequest}/audit', [PurchaseRequestController::class, 'audit'])
            ->name('api.v1.purchase-requests.audit');
        Route::post('purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])
            ->name('api.v1.purchase-requests.approve');
        Route::post('purchase-requests/{purchaseRequest}/disapprove', [PurchaseRequestController::class, 'disapprove'])
            ->name('api.v1.purchase-requests.disapprove');
        Route::post('purchase-requests/{purchaseRequest}/close', [PurchaseRequestController::class, 'close'])
            ->name('api.v1.purchase-requests.close');
        Route::post('purchase-requests/{purchaseRequest}/convert', [PurchaseRequestController::class, 'convert'])
            ->name('api.v1.purchase-requests.convert');
        Route::apiResource('purchase-requests', PurchaseRequestController::class)
            ->parameters(['purchase-requests' => 'purchaseRequest']);

        Route::get('purchase-orders/{purchaseOrder}/print', [PurchaseOrderController::class, 'print'])
            ->name('api.v1.purchase-orders.print');
        Route::get('purchase-orders/{purchaseOrder}/audit', [PurchaseOrderController::class, 'audit'])
            ->name('api.v1.purchase-orders.audit');
        Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
            ->name('api.v1.purchase-orders.approve');
        Route::post('purchase-orders/{purchaseOrder}/disapprove', [PurchaseOrderController::class, 'disapprove'])
            ->name('api.v1.purchase-orders.disapprove');
        Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])
            ->name('api.v1.purchase-orders.close');
        Route::apiResource('purchase-orders', PurchaseOrderController::class)
            ->parameters(['purchase-orders' => 'purchaseOrder']);

        Route::get('goods-receipts/{goodsReceipt}/audit', [GoodsReceiptController::class, 'audit'])
            ->name('api.v1.goods-receipts.audit');
        Route::post('goods-receipts/{goodsReceipt}/approve', [GoodsReceiptController::class, 'approve'])
            ->name('api.v1.goods-receipts.approve');
        Route::post('goods-receipts/{goodsReceipt}/disapprove', [GoodsReceiptController::class, 'disapprove'])
            ->name('api.v1.goods-receipts.disapprove');
        Route::apiResource('goods-receipts', GoodsReceiptController::class)
            ->parameters(['goods-receipts' => 'goodsReceipt']);

        Route::get('purchase-invoices/{purchaseInvoice}/audit', [PurchaseInvoiceController::class, 'audit'])
            ->name('api.v1.purchase-invoices.audit');
        Route::post('purchase-invoices/{purchaseInvoice}/approve', [PurchaseInvoiceController::class, 'approve'])
            ->name('api.v1.purchase-invoices.approve');
        Route::post('purchase-invoices/{purchaseInvoice}/disapprove', [PurchaseInvoiceController::class, 'disapprove'])
            ->name('api.v1.purchase-invoices.disapprove');
        Route::apiResource('purchase-invoices', PurchaseInvoiceController::class)
            ->parameters(['purchase-invoices' => 'purchaseInvoice']);

        Route::get('supplier-payments/{supplierPayment}/audit', [SupplierPaymentController::class, 'audit'])
            ->name('api.v1.supplier-payments.audit');
        Route::post('supplier-payments/{supplierPayment}/approve', [SupplierPaymentController::class, 'approve'])
            ->name('api.v1.supplier-payments.approve');
        Route::post('supplier-payments/{supplierPayment}/disapprove', [SupplierPaymentController::class, 'disapprove'])
            ->name('api.v1.supplier-payments.disapprove');
        Route::apiResource('supplier-payments', SupplierPaymentController::class)
            ->parameters(['supplier-payments' => 'supplierPayment']);

        // Sales: SO -> DO -> SI -> Payment In.
        Route::get('sales-orders/{salesOrder}/audit', [SalesOrderController::class, 'audit'])
            ->name('api.v1.sales-orders.audit');
        Route::post('sales-orders/{salesOrder}/approve', [SalesOrderController::class, 'approve'])
            ->name('api.v1.sales-orders.approve');
        Route::post('sales-orders/{salesOrder}/disapprove', [SalesOrderController::class, 'disapprove'])
            ->name('api.v1.sales-orders.disapprove');
        Route::post('sales-orders/{salesOrder}/close', [SalesOrderController::class, 'close'])
            ->name('api.v1.sales-orders.close');
        Route::apiResource('sales-orders', SalesOrderController::class)
            ->parameters(['sales-orders' => 'salesOrder']);

        Route::get('delivery-orders/{deliveryOrder}/print', [DeliveryOrderController::class, 'print'])
            ->name('api.v1.delivery-orders.print');
        Route::get('delivery-orders/{deliveryOrder}/audit', [DeliveryOrderController::class, 'audit'])
            ->name('api.v1.delivery-orders.audit');
        Route::post('delivery-orders/{deliveryOrder}/approve', [DeliveryOrderController::class, 'approve'])
            ->name('api.v1.delivery-orders.approve');
        Route::post('delivery-orders/{deliveryOrder}/disapprove', [DeliveryOrderController::class, 'disapprove'])
            ->name('api.v1.delivery-orders.disapprove');
        Route::apiResource('delivery-orders', DeliveryOrderController::class)
            ->parameters(['delivery-orders' => 'deliveryOrder']);

        Route::get('sales-invoices/{salesInvoice}/print', [SalesInvoiceController::class, 'print'])
            ->name('api.v1.sales-invoices.print');
        Route::get('sales-invoices/{salesInvoice}/audit', [SalesInvoiceController::class, 'audit'])
            ->name('api.v1.sales-invoices.audit');
        Route::post('sales-invoices/{salesInvoice}/approve', [SalesInvoiceController::class, 'approve'])
            ->name('api.v1.sales-invoices.approve');
        Route::post('sales-invoices/{salesInvoice}/disapprove', [SalesInvoiceController::class, 'disapprove'])
            ->name('api.v1.sales-invoices.disapprove');
        Route::apiResource('sales-invoices', SalesInvoiceController::class)
            ->parameters(['sales-invoices' => 'salesInvoice']);

        Route::get('customer-payments/{customerPayment}/audit', [CustomerPaymentController::class, 'audit'])
            ->name('api.v1.customer-payments.audit');
        Route::post('customer-payments/{customerPayment}/approve', [CustomerPaymentController::class, 'approve'])
            ->name('api.v1.customer-payments.approve');
        Route::post('customer-payments/{customerPayment}/disapprove', [CustomerPaymentController::class, 'disapprove'])
            ->name('api.v1.customer-payments.disapprove');
        Route::apiResource('customer-payments', CustomerPaymentController::class)
            ->parameters(['customer-payments' => 'customerPayment']);

        // Persediaan: saldo, ledger, transfer gudang, dan stock opname.
        Route::get('stock/balances', [StockBalanceController::class, 'index'])
            ->middleware('permission:inventory.stock.view')
            ->name('api.v1.stock.balances.index');
        Route::get('stock/ledger', [StockLedgerController::class, 'index'])
            ->middleware('permission:inventory.stock.view')
            ->name('api.v1.stock.ledger.index');

        Route::get('warehouse-transfers/{warehouseTransfer}/audit', [WarehouseTransferController::class, 'audit'])
            ->name('api.v1.warehouse-transfers.audit');
        Route::post('warehouse-transfers/{warehouseTransfer}/approve', [WarehouseTransferController::class, 'approve'])
            ->name('api.v1.warehouse-transfers.approve');
        Route::post('warehouse-transfers/{warehouseTransfer}/disapprove', [WarehouseTransferController::class, 'disapprove'])
            ->name('api.v1.warehouse-transfers.disapprove');
        Route::apiResource('warehouse-transfers', WarehouseTransferController::class)
            ->parameters(['warehouse-transfers' => 'warehouseTransfer']);

        Route::get('stock-opnames/{stockOpname}/audit', [StockOpnameController::class, 'audit'])
            ->name('api.v1.stock-opnames.audit');
        Route::post('stock-opnames/{stockOpname}/approve', [StockOpnameController::class, 'approve'])
            ->name('api.v1.stock-opnames.approve');
        Route::post('stock-opnames/{stockOpname}/disapprove', [StockOpnameController::class, 'disapprove'])
            ->name('api.v1.stock-opnames.disapprove');
        Route::apiResource('stock-opnames', StockOpnameController::class)
            ->parameters(['stock-opnames' => 'stockOpname']);

        Route::get('accounting-periods', [AccountingPeriodController::class, 'index'])
            ->name('api.v1.accounting-periods.index');
        Route::get('accounting-periods/{accountingPeriod}', [AccountingPeriodController::class, 'show'])
            ->name('api.v1.accounting-periods.show');
        Route::post('accounting-periods/{accountingPeriod}/close', [AccountingPeriodController::class, 'close'])
            ->name('api.v1.accounting-periods.close');
        Route::post('accounting-periods/{accountingPeriod}/reopen', [AccountingPeriodController::class, 'reopen'])
            ->name('api.v1.accounting-periods.reopen');

        // Laporan awal. Parameter binding dan validasi tanggal ada di service/request.
        Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])
            ->middleware('permission:report.general-ledger.view')
            ->name('api.v1.reports.general-ledger');
        Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])
            ->middleware('permission:report.trial-balance.view')
            ->name('api.v1.reports.trial-balance');
        Route::get('reports/stock-card', [ReportController::class, 'stockCard'])
            ->middleware('permission:report.stock-card.view')
            ->name('api.v1.reports.stock-card');
        Route::get('reports/stock-position', [ReportController::class, 'stockPosition'])
            ->middleware('permission:report.stock-position.view')
            ->name('api.v1.reports.stock-position');
        Route::get('reports/stock-movement', [ReportController::class, 'stockMovement'])
            ->middleware('permission:report.stock-movement.view')
            ->name('api.v1.reports.stock-movement');
        Route::get('reports/po-outstanding', [ReportController::class, 'poOutstanding'])
            ->middleware('permission:report.po-outstanding.view')
            ->name('api.v1.reports.po-outstanding');
        Route::get('reports/purchase-summary', [ReportController::class, 'purchaseSummary'])
            ->middleware('permission:report.purchase-summary.view')
            ->name('api.v1.reports.purchase-summary');
        Route::get('reports/ap-outstanding', [ReportController::class, 'apOutstanding'])
            ->middleware('permission:report.ap-outstanding.view')
            ->name('api.v1.reports.ap-outstanding');
        Route::get('reports/ap-aging', [ReportController::class, 'apAging'])
            ->middleware('permission:report.ap-aging.view')
            ->name('api.v1.reports.ap-aging');
        Route::get('reports/so-outstanding', [ReportController::class, 'soOutstanding'])
            ->middleware('permission:report.so-outstanding.view')
            ->name('api.v1.reports.so-outstanding');
        Route::get('reports/sales-summary', [ReportController::class, 'salesSummary'])
            ->middleware('permission:report.sales-summary.view')
            ->name('api.v1.reports.sales-summary');
        Route::get('reports/ar-outstanding', [ReportController::class, 'arOutstanding'])
            ->middleware('permission:report.ar-outstanding.view')
            ->name('api.v1.reports.ar-outstanding');
        Route::get('reports/ar-aging', [ReportController::class, 'arAging'])
            ->middleware('permission:report.ar-aging.view')
            ->name('api.v1.reports.ar-aging');
        Route::get('reports/income-statement', [ReportController::class, 'incomeStatement'])
            ->middleware('permission:report.income-statement.view')
            ->name('api.v1.reports.income-statement');
    });
});
