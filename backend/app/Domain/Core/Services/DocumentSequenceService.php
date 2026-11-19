<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Exceptions\ApiException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Penomoran dokumen: {PREFIX}/{YYYY}/{MM}/{0000}.
 *
 * Alur aman untuk konkurensi:
 * 1. insertOrIgnore membuat baris periode bila belum ada (tidak error saat balapan).
 * 2. lockForUpdate mengunci baris sehingga hanya satu proses yang menaikkan nomor.
 * 3. Unique index (doc_type, year, month) menjadi jaring pengaman terakhir.
 *
 * DB::transaction dengan attempts > 1 mengulang otomatis bila PostgreSQL
 * mendeteksi deadlock atau serialization failure.
 */
final class DocumentSequenceService
{
    /** @var list<string> */
    public const PREFIXES = [
        'PR', 'PO', 'GR', 'PI', 'PAYOUT',
        'SO', 'DO', 'SI', 'PAYIN',
        'TRF', 'OPN', 'ADJ', 'JV', 'PRD',
    ];

    public function next(string $docType, ?CarbonInterface $date = null): string
    {
        $prefix = strtoupper(trim($docType));

        if (! in_array($prefix, self::PREFIXES, true)) {
            throw ApiException::unprocessable("Jenis dokumen tidak dikenal: {$prefix}.", 'UNKNOWN_DOC_TYPE');
        }

        $date ??= now();
        $year = (int) $date->year;
        $month = (int) $date->month;

        $number = DB::transaction(function () use ($prefix, $year, $month): int {
            $now = now();

            DB::table('document_sequences')->insertOrIgnore([
                'doc_type' => $prefix,
                'year' => $year,
                'month' => $month,
                'last_number' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = DB::table('document_sequences')
                ->where('doc_type', $prefix)
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException("Baris sequence {$prefix}/{$year}/{$month} tidak dapat dibuat.");
            }

            $next = ((int) $row->last_number) + 1;

            DB::table('document_sequences')
                ->where('id', $row->id)
                ->update(['last_number' => $next, 'updated_at' => $now]);

            return $next;
        }, 5);

        return $this->format($prefix, $year, $month, $number);
    }

    public function format(string $prefix, int $year, int $month, int $number): string
    {
        return sprintf('%s/%04d/%02d/%04d', $prefix, $year, $month, $number);
    }
}
