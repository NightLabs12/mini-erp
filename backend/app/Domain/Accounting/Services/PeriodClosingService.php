<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\SettingService;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tutup dan buka kembali periode akuntansi.
 *
 * Validasi tutup periode mengikuti SPEC 3.7:
 * 1. periode sebelumnya sudah closed (bila barisnya ada);
 * 2. seluruh jurnal periode seimbang;
 * 3. tidak ada dokumen draft bila setting require_no_draft_on_close aktif;
 * 4. rekonsiliasi GL dengan sumber dokumen tidak menemukan selisih.
 *
 * Gagal validasi mengembalikan daftar penyebab, bukan satu pesan kabur.
 */
final class PeriodClosingService
{
    public function __construct(
        private readonly ReconciliationService $reconciliation,
        private readonly SettingService $settings,
        private readonly AuditTrailService $audit,
    ) {}

    public function close(AccountingPeriod $period): AccountingPeriod
    {
        return DB::transaction(function () use ($period): AccountingPeriod {
            $locked = $this->lock($period);

            if (! $locked->isOpen()) {
                throw ApiException::conflict('Periode sudah ditutup.', 'PERIOD_ALREADY_CLOSED');
            }

            $errors = $this->validationErrors($locked);

            if ($errors !== []) {
                throw ApiException::conflict(
                    'Periode belum dapat ditutup.',
                    'PERIOD_CLOSE_FAILED',
                    ['reasons' => $errors],
                );
            }

            $locked->forceFill([
                'status' => AccountingPeriod::STATUS_CLOSED,
                'closed_by' => $this->actorId(),
                'closed_at' => now(),
                'reopened_by' => null,
                'reopened_at' => null,
            ])->save();

            $this->audit->record($locked, 'period_closed', [], [
                'year' => $locked->year,
                'month' => $locked->month,
                'status' => AccountingPeriod::STATUS_CLOSED,
            ]);

            return $locked->refresh();
        }, 5);
    }

    public function reopen(AccountingPeriod $period): AccountingPeriod
    {
        return DB::transaction(function () use ($period): AccountingPeriod {
            $locked = $this->lock($period);

            if ($locked->isOpen()) {
                throw ApiException::conflict('Periode sudah terbuka.', 'PERIOD_ALREADY_OPEN');
            }

            $laterClosed = AccountingPeriod::query()
                ->where(static function ($query) use ($locked): void {
                    $query->where('year', '>', $locked->year)
                        ->orWhere(static function ($nested) use ($locked): void {
                            $nested->where('year', $locked->year)->where('month', '>', $locked->month);
                        });
                })
                ->where('status', AccountingPeriod::STATUS_CLOSED)
                ->exists();

            if ($laterClosed) {
                throw ApiException::conflict(
                    'Periode setelahnya sudah ditutup. Buka periode tersebut lebih dulu.',
                    'PERIOD_LATER_CLOSED',
                );
            }

            $locked->forceFill([
                'status' => AccountingPeriod::STATUS_OPEN,
                'closed_by' => null,
                'closed_at' => null,
                'reopened_by' => $this->actorId(),
                'reopened_at' => now(),
            ])->save();

            $this->audit->record($locked, 'period_reopened', [
                'status' => AccountingPeriod::STATUS_CLOSED,
            ], [
                'year' => $locked->year,
                'month' => $locked->month,
                'status' => AccountingPeriod::STATUS_OPEN,
            ]);

            return $locked->refresh();
        }, 5);
    }

    /**
     * @return list<string>
     */
    public function validationErrors(AccountingPeriod $period): array
    {
        $errors = [];

        $previous = $period->previous();

        if ($previous !== null && $previous->isOpen()) {
            $errors[] = "Periode sebelumnya ({$previous->label()}) masih open.";
        }

        foreach ($this->unbalancedJournals($period) as $journal) {
            $errors[] = "Jurnal {$journal} tidak seimbang.";
        }

        if ($this->settings->bool('require_no_draft_on_close', true)) {
            $draftCount = $this->draftCount($period);

            if ($draftCount > 0) {
                $errors[] = "Masih ada {$draftCount} dokumen draft pada periode ini.";
            }
        }

        foreach ($this->reconciliation->mismatches($period) as $mismatch) {
            $errors[] = "Rekonsiliasi {$mismatch['check']} selisih {$mismatch['difference']}.";
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function unbalancedJournals(AccountingPeriod $period): array
    {
        $rows = DB::select(
            'SELECT j.id, j.journal_no
               FROM journals j
               LEFT JOIN journal_lines l ON l.journal_id = j.id
              WHERE j.period_id = ?
                AND j.deleted_at IS NULL
              GROUP BY j.id, j.journal_no
             HAVING COALESCE(SUM(l.debit), 0) <> COALESCE(SUM(l.credit), 0)',
            [$period->getKey()],
        );

        return array_map(
            static fn (object $row): string => (string) ($row->journal_no ?? '#'.$row->id),
            $rows,
        );
    }

    private function draftCount(AccountingPeriod $period): int
    {
        $count = DB::table('journals')
            ->where('period_id', $period->getKey())
            ->where('status', 'draft')
            ->whereNull('deleted_at')
            ->count();

        /** @var list<string> $documents */
        $documents = config('master_usage.document_tables', []);

        foreach ($documents as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status')) {
                continue;
            }

            $query = DB::table($table)->where('status', 'draft');

            if (Schema::hasColumn($table, 'period_id')) {
                $query->where('period_id', $period->getKey());
            } elseif (Schema::hasColumn($table, 'doc_date')) {
                $query->whereBetween('doc_date', [
                    $period->firstDate()->toDateString(),
                    $period->lastDate()->toDateString(),
                ]);
            } else {
                continue;
            }

            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            $count += $query->count();
        }

        return $count;
    }

    private function lock(AccountingPeriod $period): AccountingPeriod
    {
        /** @var AccountingPeriod $locked */
        $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->getKey());

        return $locked;
    }

    private function actorId(): ?int
    {
        $user = app()->bound('request') ? request()->user() : null;
        $id = $user?->getAuthIdentifier() ?? Auth::id();

        return $id === null ? null : (int) $id;
    }
}
