<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Invoice dari satu atau beberapa Goods Receipt.
 *
 * gr_unit_cost dan gr_value adalah snapshot biaya GR yang ditagih, dipakai
 * untuk membersihkan GR/IR dan menghitung selisih harga pembelian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->date('doc_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('due_date');
            $table->string('status', 10)->default('draft');
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
            $table->index('supplier_id');
        });

        Schema::create('purchase_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_invoice_id')
                ->constrained('purchase_invoices')
                ->cascadeOnDelete();
            $table->foreignId('goods_receipt_item_id')->constrained('goods_receipt_items')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('unit_price_base', 18, 4);
            $table->decimal('gr_unit_cost', 18, 4);
            $table->decimal('gr_value', 18, 2);
            $table->decimal('total_value', 18, 2);
            $table->timestamps();

            $table->unique(['purchase_invoice_id', 'goods_receipt_item_id'], 'purchase_invoice_items_unique_gr_item');
            $table->index('item_id');
        });

        DB::statement(
            'ALTER TABLE purchase_invoices ADD CONSTRAINT purchase_invoices_status_check '
            ."CHECK (status IN ('draft', 'approved', 'closed', 'cancelled'))"
        );
        DB::statement(
            'ALTER TABLE purchase_invoices ADD CONSTRAINT purchase_invoices_amount_check '
            .'CHECK (subtotal >= 0 AND tax_amount >= 0 AND total >= 0 AND paid_amount >= 0 AND paid_amount <= total)'
        );
        DB::statement(
            'ALTER TABLE purchase_invoice_items ADD CONSTRAINT purchase_invoice_items_qty_check '
            .'CHECK (qty > 0 AND qty_base > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
        Schema::dropIfExists('purchase_invoices');
    }
};
