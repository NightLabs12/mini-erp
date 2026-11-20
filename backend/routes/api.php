<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountingPeriodController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ItemCategoryController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemStockController;
use App\Http\Controllers\Api\V1\ItemUnitConversionController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SellingPriceController;
use App\Http\Controllers\Api\V1\StockBalanceController;
use App\Http\Controllers\Api\V1\StockLedgerController;
use App\Http\Controllers\Api\V1\StockOpnameController;
use App\Http\Controllers\Api\V1\SupplierController;
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
    });
});
