<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satuan bertingkat per item. factor_to_base menyatakan berapa satuan base
 * dalam satu satuan ini (Pack = 12 Pcs, Dus = 144 Pcs).
 *
 * Baris konversi tidak memakai soft delete: ia bagian dari definisi item dan
 * diganti saat item diperbarui. Riwayat perubahan tetap tercatat pada audit
 * item karena perubahan konversi selalu lewat item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_unit_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('factor_to_base', 18, 4);
            $table->boolean('is_purchase_default')->default(false);
            $table->boolean('is_sales_default')->default(false);
            $table->timestamps();

            $table->unique(['item_id', 'unit_id'], 'item_unit_conversions_unique_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_unit_conversions');
    }
};
