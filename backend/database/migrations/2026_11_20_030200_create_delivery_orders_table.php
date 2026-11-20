<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Order (Surat Jalan) dari Sales Order.
 *
 * Approve mengeluarkan stok pada average cost saat keluar, menyimpan
 * cogs_unit_cost_base per baris, dan membentuk jurnal Dr HPP / Cr Persediaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->date('doc_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 10)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('doc_date');
            $table->index('status');
            $table->index('sales_order_id');
            $table->index('customer_id');
        });

        Schema::create('delivery_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_order_id')
                ->constrained('delivery_orders')
                ->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->nullable()
                ->constrained('sales_order_items')
                ->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('cogs_unit_cost_base', 18, 4)->default(0);
            $table->decimal('total_cogs', 18, 2)->default(0);
            $table->decimal('invoiced_qty_base', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(
                ['delivery_order_id', 'sales_order_item_id'],
                'delivery_order_items_unique_so_item',
            );
            $table->index('item_id');
        });

        DB::statement(
            'ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_status_check '
            ."CHECK (status IN ('draft', 'approved', 'closed', 'cancelled'))"
        );
        DB::statement(
            'ALTER TABLE delivery_order_items ADD CONSTRAINT delivery_order_items_qty_check '
            .'CHECK (qty > 0 AND qty_base > 0 AND invoiced_qty_base >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_order_items');
        Schema::dropIfExists('delivery_orders');
    }
};
