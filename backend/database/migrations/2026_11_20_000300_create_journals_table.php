<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Header jurnal. Jurnal manual dibuat draft tanpa nomor; nomor JV diberikan
 * saat approve/posting agar tanggal final sudah pasti. Jurnal dari dokumen
 * (fase berikutnya) langsung posted lewat JournalService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table): void {
            $table->id();
            $table->string('journal_no', 30)->nullable()->unique();
            $table->date('journal_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->string('status', 10)->default('draft');
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->foreignId('reversal_of_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('journal_date');
            $table->index('status');
            $table->index(['source_type', 'source_id']);
        });

        DB::statement(
            "ALTER TABLE journals ADD CONSTRAINT journals_status_check CHECK (status IN ('draft', 'posted', 'reversed'))"
        );
        DB::statement('ALTER TABLE journals ADD CONSTRAINT journals_totals_check CHECK (total_debit >= 0 AND total_credit >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
