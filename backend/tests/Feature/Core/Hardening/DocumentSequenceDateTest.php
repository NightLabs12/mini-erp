<?php

declare(strict_types=1);

use App\Domain\Core\Services\DocumentSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Nomor harus mengikuti tanggal dokumen, bukan jam sistem. Dokumen bertanggal
 * bulan lalu tetap masuk seri bulan itu, meskipun dibuat hari ini.
 */
it('mengikuti doc_date, bukan tanggal hari ini', function (): void {
    $service = app(DocumentSequenceService::class);

    $lastMonth = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();

    $number = $service->next('PO', $lastMonth);

    expect($number)->toBe(sprintf('PO/%04d/%02d/0001', $lastMonth->year, $lastMonth->month))
        ->and($number)->not->toBe(sprintf('PO/%04d/%02d/0001', now()->year, now()->month));
});

it('mereset nomor per bulan dan mempertahankan seri bulan sebelumnya', function (): void {
    $service = app(DocumentSequenceService::class);

    $january = CarbonImmutable::parse('2026-01-10');
    $february = CarbonImmutable::parse('2026-02-02');

    expect($service->next('GR', $january))->toBe('GR/2026/01/0001')
        ->and($service->next('GR', $january))->toBe('GR/2026/01/0002')
        ->and($service->next('GR', $february))->toBe('GR/2026/02/0001')
        ->and($service->next('GR', $january))->toBe('GR/2026/01/0003');
});

it('tidak menyisakan celah nomor saat transaksi dibatalkan', function (): void {
    $service = app(DocumentSequenceService::class);
    $date = CarbonImmutable::parse('2026-05-20');

    $before = $service->next('SI', $date);

    expect($before)->toBe('SI/2026/05/0001');

    try {
        DB::transaction(function () use ($service, $date): void {
            $inside = $service->next('SI', $date);

            expect($inside)->toBe('SI/2026/05/0002');

            throw new RuntimeException('dibatalkan');
        });
    } catch (RuntimeException) {
        // diharapkan
    }

    // Nomor yang diambil di dalam transaksi yang gagal harus dikembalikan,
    // sehingga dokumen berikutnya tetap berurutan tanpa celah.
    expect($service->next('SI', $date))->toBe('SI/2026/05/0002');

    expect(DB::table('document_sequences')
        ->where('doc_type', 'SI')
        ->where('year', 2026)
        ->where('month', 5)
        ->value('last_number'))->toBe(2);
});

it('tidak menyisakan celah saat dua nomor diambil lalu transaksi dibatalkan', function (): void {
    $service = app(DocumentSequenceService::class);
    $date = CarbonImmutable::parse('2026-06-01');

    try {
        DB::transaction(function () use ($service, $date): void {
            $service->next('JV', $date);
            $service->next('JV', $date);

            throw new RuntimeException('dibatalkan');
        });
    } catch (RuntimeException) {
    }

    expect($service->next('JV', $date))->toBe('JV/2026/06/0001');
});

it('memakai tanggal hari ini hanya bila doc_date tidak diberikan', function (): void {
    $service = app(DocumentSequenceService::class);

    $today = now();

    expect($service->next('ADJ'))->toBe(sprintf('ADJ/%04d/%02d/0001', $today->year, $today->month));
});
