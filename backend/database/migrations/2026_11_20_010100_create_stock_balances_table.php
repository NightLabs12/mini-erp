<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo stok per item dan gudang, dalam satuan base.
 *
 * Baris dikunci dengan lockForUpdate dalam urutan deterministik item_id lalu
 * warehouse_id oleh StockService. value adalah nilai persediaan 2 desimal,
 * avg_cost disimpan 4 desimal. Tidak ada CHECK qty >= 0 karena setting
 * allow_negative_stock dapat mengizinkan saldo negatif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('qty_base', 18, 4)->default(0);
            $table->decimal('avg_cost', 18, 4)->default(0);
            $table->decimal('value', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id'], 'stock_balances_item_warehouse_unique');
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
