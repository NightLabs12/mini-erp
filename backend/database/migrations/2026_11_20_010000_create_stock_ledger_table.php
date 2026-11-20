<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger stok append-only (SPEC 3.5).
 *
 * Setiap baris adalah satu mutasi masuk atau keluar, tidak pernah keduanya.
 * balance_qty, balance_value, dan avg_cost_after adalah saldo setelah baris
 * ini diposting, sehingga laporan dapat membaca saldo berjalan tanpa
 * menghitung ulang seluruh riwayat.
 *
 * Trigger PostgreSQL menolak UPDATE dan DELETE. Pembalikan dokumen selalu
 * membuat baris baru dengan reversal_of_id menunjuk baris asal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('movement_date');
            $table->string('doc_type', 20);
            $table->unsignedBigInteger('doc_id')->nullable();
            $table->unsignedBigInteger('doc_line_id')->nullable();
            $table->decimal('qty_in_base', 18, 4)->default(0);
            $table->decimal('qty_out_base', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_value', 18, 2)->default(0);
            $table->decimal('balance_qty', 18, 4)->default(0);
            $table->decimal('balance_value', 18, 2)->default(0);
            $table->decimal('avg_cost_after', 18, 4)->default(0);
            $table->foreignId('reversal_of_id')->nullable()->constrained('stock_ledger')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'warehouse_id', 'movement_date'], 'stock_ledger_item_warehouse_date_idx');
            $table->index(['doc_type', 'doc_id'], 'stock_ledger_doc_idx');
            $table->index('movement_date');
            $table->index('reversal_of_id');
        });

        DB::statement(
            'ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_qty_check '
            .'CHECK (qty_in_base >= 0 AND qty_out_base >= 0 '
            .'AND ((qty_in_base > 0) <> (qty_out_base > 0)))'
        );

        // Satu baris asal hanya boleh dibalik sekali.
        DB::statement(
            'CREATE UNIQUE INDEX stock_ledger_reversal_unique '
            .'ON stock_ledger (reversal_of_id) WHERE reversal_of_id IS NOT NULL'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_ledger_reject_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'STOCK_LEDGER_IMMUTABLE: stock_ledger bersifat append-only'
                    USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stock_ledger_no_update
            BEFORE UPDATE ON stock_ledger
            FOR EACH ROW EXECUTE FUNCTION stock_ledger_reject_mutation();

            CREATE TRIGGER stock_ledger_no_delete
            BEFORE DELETE ON stock_ledger
            FOR EACH ROW EXECUTE FUNCTION stock_ledger_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_ledger_no_update ON stock_ledger');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_ledger_no_delete ON stock_ledger');
        DB::unprepared('DROP FUNCTION IF EXISTS stock_ledger_reject_mutation()');

        Schema::dropIfExists('stock_ledger');
    }
};
