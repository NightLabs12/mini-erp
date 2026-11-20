<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Header transfer gudang. Transfer tidak membentuk jurnal karena hanya
 * memindahkan nilai persediaan antar gudang dalam entitas yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->date('doc_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 10)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('doc_date');
            $table->index('status');
            $table->index('from_warehouse_id');
            $table->index('to_warehouse_id');
        });

        DB::statement(
            'ALTER TABLE warehouse_transfers ADD CONSTRAINT warehouse_transfers_status_check '
            ."CHECK (status IN ('draft', 'approved', 'closed', 'cancelled'))"
        );
        DB::statement(
            'ALTER TABLE warehouse_transfers ADD CONSTRAINT warehouse_transfers_warehouse_check '
            .'CHECK (from_warehouse_id <> to_warehouse_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfers');
    }
};
