<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris transfer. qty disimpan pada satuan input, qty_base hasil konversi.
 * unit_cost_base dan total_value baru diisi saat approve, memakai average
 * cost gudang asal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_transfer_id')
                ->constrained('warehouse_transfers')
                ->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_cost_base', 18, 4)->nullable();
            $table->decimal('total_value', 18, 2)->nullable();
            $table->timestamps();

            $table->unique(['warehouse_transfer_id', 'item_id'], 'warehouse_transfer_items_unique_item');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfer_items');
    }
};
