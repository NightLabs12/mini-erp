<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan ringan setiap request mutasi (POST, PUT, PATCH, DELETE).
 * Dipakai untuk investigasi, bukan pengganti audit trail domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['path', 'created_at'], 'api_request_logs_path_index');
            $table->index(['user_id', 'created_at'], 'api_request_logs_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
