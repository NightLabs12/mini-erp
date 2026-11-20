<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Order beserta barisnya.
 *
 * delivered_qty_base dan invoiced_qty_base adalah akumulasi qty base yang
 * sudah dikirim/ditagih. unit_price_base adalah harga jual bersih per satuan
 * base (setelah diskon baris, tanpa pajak) dan menjadi harga default invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->date('doc_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 10)->default('draft');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_rate', 6, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->boolean('closed_manually')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('doc_date');
            $table->index('status');
            $table->index('customer_id');
            $table->index('warehouse_id');
        });

        Schema::create('sales_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')
                ->constrained('sales_orders')
                ->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('unit_price_base', 18, 4)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->decimal('delivered_qty_base', 18, 4)->default(0);
            $table->decimal('invoiced_qty_base', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['sales_order_id', 'item_id'], 'sales_order_items_unique_item');
            $table->index('item_id');
        });

        DB::statement(
            'ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_status_check '
            ."CHECK (status IN ('draft', 'approved', 'closed', 'cancelled'))"
        );
        DB::statement(
            'ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_items_qty_check '
            .'CHECK (qty >= 0 AND qty_base >= 0 AND delivered_qty_base >= 0 AND invoiced_qty_base >= 0)'
        );
        DB::statement(
            'ALTER TABLE sales_order_items ADD CONSTRAINT sales_order_items_discount_check '
            .'CHECK (discount_pct >= 0 AND discount_pct <= 100)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};
