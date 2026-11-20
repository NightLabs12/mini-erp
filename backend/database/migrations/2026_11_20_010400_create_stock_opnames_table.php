<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Header stock opname. Selisih yang disetujui membentuk mutasi stok dan
 * jurnal penyesuaian lewat JournalService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->date('doc_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
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
            $table->index('warehouse_id');
        });

        DB::statement(
            'ALTER TABLE stock_opnames ADD CONSTRAINT stock_opnames_status_check '
            ."CHECK (status IN ('draft', 'approved', 'closed', 'cancelled'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opnames');
    }
};
