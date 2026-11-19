<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harga jual per item, satuan, dan opsional per customer.
 *
 * customer_id null berarti harga umum. valid_from dan valid_to boleh null
 * (berlaku tanpa batas). Prioritas lookup: harga khusus customer yang valid,
 * lalu harga umum; bila beberapa baris valid, valid_from terbaru menang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selling_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->decimal('price', 18, 4);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_id', 'unit_id', 'customer_id'], 'selling_prices_lookup_index');
            $table->index(['valid_from', 'valid_to'], 'selling_prices_validity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selling_prices');
    }
};
