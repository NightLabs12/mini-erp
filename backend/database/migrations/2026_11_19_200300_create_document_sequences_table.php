<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penomoran dokumen per jenis, tahun, dan bulan.
 *
 * Unique (doc_type, year, month) adalah kunci kebenaran: bila dua proses
 * mencoba membuat baris yang sama, salah satunya gagal dan diulang oleh
 * DocumentSequenceService. lockForUpdate mencegah nomor ganda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['doc_type', 'year', 'month'], 'document_sequences_unique_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
