<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Request. Tidak berdampak ke stok maupun jurnal; hanya dokumen
 * permintaan yang dapat dikonversi menjadi Purchase Order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->date('doc_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 10)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('doc_date');
            $table->index('status');
            $table->index('supplier_id');
        });

        Schema::create('purchase_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')
                ->constrained('purchase_requests')
                ->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_base', 18, 4);
            $table->timestamps();

            $table->unique(['purchase_request_id', 'item_id'], 'purchase_request_items_unique_item');
        });

        DB::statement(
            'ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_status_check '
            ."CHECK (status IN ('draft', 'approved', 'closed', 'cancelled'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
    }
};
