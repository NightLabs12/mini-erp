<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of Accounts bertingkat.
 *
 * Akun induk (is_postable=false) hanya untuk pengelompokan laporan; jurnal
 * hanya boleh memakai akun postable. parent_id membentuk hierarki dan level
 * disimpan agar laporan tidak perlu rekursi untuk tampilan dasar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->string('normal_balance', 1);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('level')->default(1);
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'type']);
            $table->index('is_postable');
        });

        DB::statement(
            'ALTER TABLE accounts ADD CONSTRAINT accounts_type_check '
            ."CHECK (type IN ('asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'))"
        );
        DB::statement(
            "ALTER TABLE accounts ADD CONSTRAINT accounts_normal_balance_check CHECK (normal_balance IN ('D', 'C'))"
        );
        DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_level_check CHECK (level >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
