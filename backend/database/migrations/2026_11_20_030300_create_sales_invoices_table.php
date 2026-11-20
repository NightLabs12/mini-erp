<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Invoice dari satu atau beberapa Delivery Order.
 *
 * mode draft berarti invoice masih dapat diubah; final berarti sudah
 * diposting ke jurnal dan tidak dapat diubah. status tetap mengikuti state
 * machine dokumen draft/approved/closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->date('doc_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('due_date');
            $table->string('status', 10)->default('draft');
            $table->string('mode', 10)->default('draft');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_rate', 6, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('doc_date');
            $table->index('due_date');
            $table->index('status');
            $table->index('customer_id');
        });

        Schema::create('sales_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_invoice_id')
                ->constrained('sales_invoices')
                ->cascadeOnDelete();
            $table->foreignId('delivery_order_item_id')->constrained('delivery_order_items')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('unit_price_base', 18, 4);
            $table->decimal('total_value', 18, 2);
            $table->timestamps();

            $table->unique(
                ['sales_invoice_id', 'delivery_order_item_id'],
                'sales_invoice_items_unique_do_item',
            );
            $table->index('item_id');
        });

        DB::statement(
            'ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_status_check '
            ."CHECK (status IN ('draft', 'approved', 'closed', 'cancelled'))"
        );
        DB::statement(
            'ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_mode_check '
            ."CHECK (mode IN ('draft', 'final'))"
        );
        DB::statement(
            'ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_amount_check '
            .'CHECK (subtotal >= 0 AND tax_amount >= 0 AND total >= 0 AND paid_amount >= 0 AND paid_amount <= total)'
        );
        DB::statement(
            'ALTER TABLE sales_invoice_items ADD CONSTRAINT sales_invoice_items_qty_check '
            .'CHECK (qty > 0 AND qty_base > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_items');
        Schema::dropIfExists('sales_invoices');
    }
};
