<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft delete untuk user, plus unique email parsial.
 *
 * Email hanya unik di antara user aktif, sehingga user yang dihapus tidak
 * menghalangi pemakaian ulang email (SPEC 3.12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_unique');
        });

        DB::statement('CREATE UNIQUE INDEX users_email_unique_active ON users (email) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_unique_active');

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('email');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
