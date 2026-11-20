<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goods Receipt dari Purchase Order. Approve menerima stok pada biaya PO
 * bersih per satuan base dan membentuk jurnal Dr Persediaan / Cr GR/IR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->date('doc_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
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
            $table->index('purchase_order_id');
            $table->index('supplier_id');
        });

        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')
                ->constrained('goods_receipts')
                ->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()
                ->constrained('purchase_order_items')
                ->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_cost_base', 18, 4)->default(0);
            $table->decimal('total_value', 18, 2)->default(0);
            $table->decimal('invoiced_qty_base', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['goods_receipt_id', 'purchase_order_item_id'], 'goods_receipt_items_unique_po_item');
            $table->index('item_id');
        });

        DB::statement(
            'ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_status_check '
            ."CHECK (status IN ('draft', 'approved', 'closed', 'cancelled'))"
        );
        DB::statement(
            'ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_qty_check '
            .'CHECK (qty > 0 AND qty_base > 0 AND invoiced_qty_base >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
