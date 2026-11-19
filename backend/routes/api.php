<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ItemCategoryController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemUnitConversionController;
use App\Http\Controllers\Api\V1\SellingPriceController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WarehouseController;
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
    });
});
