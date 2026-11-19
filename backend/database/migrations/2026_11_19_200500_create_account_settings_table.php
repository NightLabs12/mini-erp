<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan key akun (mis. grir, cogs) ke akun COA.
 *
 * Tabel akun baru dibuat pada Fase 2, sehingga foreign key ke accounts
 * ditambahkan pada migrasi Fase 2. Kolom account_id sudah disiapkan di sini
 * agar seeder akun bisa langsung mengisinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_settings');
    }
};
