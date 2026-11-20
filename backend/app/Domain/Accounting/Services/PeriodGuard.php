<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Guard global periode akuntansi.
 *
 * Semua dokumen yang membentuk jurnal wajib memanggil assertOpen() sebelum
 * menulis. Dengan begitu aturan "periode closed tidak boleh berubah" hanya
 * berada di satu tempat.
 */
final class PeriodGuard
{
    public function assertOpen(CarbonInterface|string $date, bool $lock = false): AccountingPeriod
    {
        $day = $date instanceof CarbonInterface
            ? CarbonImmutable::parse($date->toDateString())
            : CarbonImmutable::parse($date);

        $query = AccountingPeriod::query()
            ->where('year', $day->year)
            ->where('month', $day->month);

        if ($lock) {
            $query->lockForUpdate();
        }

        $period = $query->first();

        if ($period === null) {
            throw ApiException::unprocessable(
                "Periode akuntansi {$day->format('Y-m')} belum dibuka.",
                'PERIOD_NOT_FOUND',
                ['journal_date' => ['Buka periode akuntansi terlebih dahulu.']],
            );
        }

        if (! $period->isOpen()) {
            throw ApiException::unprocessable(
                "Periode akuntansi {$period->label()} sudah ditutup.",
                'PERIOD_CLOSED',
                ['journal_date' => ["Periode {$period->label()} tidak menerima posting."]],
            );
        }

        return $period;
    }

    public function periodFor(CarbonInterface|string $date): ?AccountingPeriod
    {
        $day = $date instanceof CarbonInterface
            ? CarbonImmutable::parse($date->toDateString())
            : CarbonImmutable::parse($date);

        return AccountingPeriod::query()
            ->where('year', $day->year)
            ->where('month', $day->month)
            ->first();
    }
}
