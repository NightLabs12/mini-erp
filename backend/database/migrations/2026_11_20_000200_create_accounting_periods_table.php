<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Periode akuntansi bulanan. Satu baris per tahun-bulan; status open/closed.
 * Posting ke periode closed ditolak PeriodGuard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->smallInteger('year');
            $table->smallInteger('month');
            $table->string('status', 10)->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->index('status');
        });

        DB::statement('ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_month_check CHECK (month BETWEEN 1 AND 12)');
        DB::statement(
            "ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_status_check CHECK (status IN ('open', 'closed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
