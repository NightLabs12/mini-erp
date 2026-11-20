<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris stock opname. system_qty_base dan diff_qty_base dihitung ulang saat
 * approve terhadap saldo terkini, sehingga hasil akhir selalu sama dengan
 * counted_qty_base. total_value bertanda: positif untuk selisih plus,
 * negatif untuk selisih minus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opname_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_opname_id')
                ->constrained('stock_opnames')
                ->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('system_qty_base', 18, 4)->default(0);
            $table->decimal('counted_qty_base', 18, 4)->default(0);
            $table->decimal('diff_qty_base', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('total_value', 18, 2)->nullable();
            $table->timestamps();

            $table->unique(['stock_opname_id', 'item_id'], 'stock_opname_items_unique_item');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
    }
};
