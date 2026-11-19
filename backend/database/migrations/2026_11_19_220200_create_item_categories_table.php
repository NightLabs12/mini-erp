<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori menentukan jenis persediaan dan akun default per kategori.
 *
 * Kolom akun sengaja tanpa foreign key: tabel accounts baru dibuat pada Fase
 * Accounting. FK ditambahkan saat itu, bersama pemetaan account_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->string('item_type', 20)->default('trading');
            $table->unsignedBigInteger('inventory_account_id')->nullable();
            $table->unsignedBigInteger('cogs_account_id')->nullable();
            $table->unsignedBigInteger('sales_account_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('item_type');
        });

        DB::statement(
            'CREATE UNIQUE INDEX item_categories_code_unique_active ON item_categories (code) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
