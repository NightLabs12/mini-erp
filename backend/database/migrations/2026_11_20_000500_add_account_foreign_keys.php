<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FK akun yang sengaja ditunda sampai tabel accounts ada.
 *
 * restrictOnDelete: akun yang sudah dipakai mapping tidak boleh dihapus tanpa
 * membersihkan referensinya lebih dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table): void {
            $table->foreign('inventory_account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('cogs_account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('sales_account_id')->references('id')->on('accounts')->restrictOnDelete();
        });

        Schema::table('account_settings', function (Blueprint $table): void {
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table): void {
            $table->dropForeign(['inventory_account_id']);
            $table->dropForeign(['cogs_account_id']);
            $table->dropForeign(['sales_account_id']);
        });

        Schema::table('account_settings', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
        });
    }
};
