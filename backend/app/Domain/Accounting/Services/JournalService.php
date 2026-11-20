<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\DocumentSequenceService;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu pembuatan dan pembalikan jurnal.
 *
 * Semua aritmetika uang memakai Decimal (bcmath), tidak pernah float. Setiap
 * operasi berjalan dalam DB::transaction dan memeriksa PeriodGuard, sehingga
 * jurnal tidak dapat masuk ke periode closed.
 */
final class JournalService
{
    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly DocumentSequenceService $sequences,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * Posting jurnal yang sudah seimbang.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array{type?: string, id?: int|string|null}|string  $source
     */
    public function post(
        array $lines,
        array|string $source,
        CarbonInterface|string $date,
        ?string $description = null,
    ): Journal {
        return DB::transaction(function () use ($lines, $source, $date, $description): Journal {
            $day = $this->day($date);
            $period = $this->periods->assertOpen($day, true);
            $normalized = $this->normalizeLines($lines, true);
            $origin = $this->source($source);

            $journal = Journal::query()->create([
                'journal_no' => $this->sequences->next('JV', $day),
                'journal_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'source_type' => $origin['type'],
                'source_id' => $origin['id'],
                'description' => $description,
                'status' => Journal::STATUS_POSTED,
                'total_debit' => $normalized['total_debit'],
                'total_credit' => $normalized['total_credit'],
                'created_by' => $this->actorId(),
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ]);

            $this->insertLines($journal, $normalized['lines'], $day);
            $this->recordPosted($journal);

            return $this->loadJournal($journal);
        }, 5);
    }

    /**
     * Jurnal manual disimpan sebagai draft. Nomor JV baru diberikan saat
     * approve agar nomor mengikuti tanggal final.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array{type?: string, id?: int|string|null}|string  $source
     */
    public function createDraft(
        array $lines,
        CarbonInterface|string $date,
        ?string $description = null,
        array|string $source = 'manual',
    ): Journal {
        return DB::transaction(function () use ($lines, $date, $description, $source): Journal {
            $day = $this->day($date);
            $period = $this->periods->assertOpen($day);
            $normalized = $this->normalizeLines($lines, false);
            $origin = $this->source($source);

            $journal = Journal::query()->create([
                'journal_no' => null,
                'journal_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'source_type' => $origin['type'],
                'source_id' => $origin['id'],
                'description' => $description,
                'status' => Journal::STATUS_DRAFT,
                'total_debit' => $normalized['total_debit'],
                'total_credit' => $normalized['total_credit'],
                'created_by' => $this->actorId(),
            ]);

            $this->insertLines($journal, $normalized['lines'], $day);

            return $this->loadJournal($journal);
        }, 5);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function updateDraft(
        Journal $journal,
        array $lines,
        CarbonInterface|string|null $date = null,
        ?string $description = null,
    ): Journal {
        return DB::transaction(function () use ($journal, $lines, $date, $description): Journal {
            $locked = $this->lockJournal($journal);

            if ($locked->status !== Journal::STATUS_DRAFT) {
                throw ApiException::conflict('Hanya jurnal draft yang boleh diubah.', 'JOURNAL_NOT_DRAFT');
            }

            $day = $date === null ? $this->day($locked->journal_date) : $this->day($date);
            $period = $this->periods->assertOpen($day);
            $normalized = $this->normalizeLines($lines, false);

            $locked->forceFill([
                'journal_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'description' => $description,
                'total_debit' => $normalized['total_debit'],
                'total_credit' => $normalized['total_credit'],
            ])->save();

            $locked->lines()->delete();
            $this->insertLines($locked, $normalized['lines'], $day);

            return $this->loadJournal($locked);
        }, 5);
    }

    public function deleteDraft(Journal $journal): void
    {
        DB::transaction(function () use ($journal): void {
            $locked = $this->lockJournal($journal);

            if ($locked->status !== Journal::STATUS_DRAFT) {
                throw ApiException::conflict('Hanya jurnal draft yang boleh dihapus.', 'JOURNAL_NOT_DRAFT');
            }

            $locked->delete();
        }, 5);
    }

    public function approve(Journal $journal): Journal
    {
        return DB::transaction(function () use ($journal): Journal {
            $locked = $this->lockJournal($journal);

            if ($locked->status !== Journal::STATUS_DRAFT) {
                throw ApiException::conflict('Jurnal sudah diposting atau dibalik.', 'JOURNAL_NOT_DRAFT');
            }

            $day = $this->day($locked->journal_date);
            $period = $this->periods->assertOpen($day, true);
            $normalized = $this->normalizeLines($this->linesOf($locked), true);

            $locked->forceFill([
                'journal_no' => $locked->journal_no ?? $this->sequences->next('JV', $day),
                'period_id' => $period->getKey(),
                'status' => Journal::STATUS_POSTED,
                'total_debit' => $normalized['total_debit'],
                'total_credit' => $normalized['total_credit'],
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->recordPosted($locked);

            return $this->loadJournal($locked);
        }, 5);
    }

    /**
     * Membuat jurnal pembalik dengan debit/credit ditukar, lalu menandai
     * jurnal asal reversed. Kedua jurnal tetap ada untuk jejak audit.
     */
    public function reverse(Journal $journal): Journal
    {
        return DB::transaction(function () use ($journal): Journal {
            $original = $this->lockJournal($journal);

            if ($original->status !== Journal::STATUS_POSTED) {
                throw ApiException::conflict(
                    'Hanya jurnal posted yang dapat dibalik.',
                    'JOURNAL_NOT_REVERSIBLE',
                );
            }

            $day = $this->day($original->journal_date);
            $period = $this->periods->assertOpen($day, true);
            $lines = $this->linesOf($original);

            $reversed = array_map(
                static fn (array $line): array => [
                    ...$line,
                    'debit' => $line['credit'],
                    'credit' => $line['debit'],
                ],
                $lines,
            );

            $reversal = Journal::query()->create([
                'journal_no' => $this->sequences->next('JV', $day),
                'journal_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'source_type' => 'reversal',
                'source_id' => $original->getKey(),
                'description' => 'Pembalik '.($original->journal_no ?? '#'.$original->getKey()),
                'status' => Journal::STATUS_POSTED,
                'total_debit' => $original->total_credit,
                'total_credit' => $original->total_debit,
                'reversal_of_id' => $original->getKey(),
                'created_by' => $this->actorId(),
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ]);

            $normalized = $this->normalizeLines($reversed, true);
            $this->insertLines($reversal, $normalized['lines'], $day);

            $original->forceFill(['status' => Journal::STATUS_REVERSED])->save();

            $this->audit->record($original, 'reversed', ['status' => Journal::STATUS_POSTED], [
                'status' => Journal::STATUS_REVERSED,
                'reversal_id' => $reversal->getKey(),
            ]);
            $this->recordPosted($reversal);

            return $this->loadJournal($reversal);
        }, 5);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, total_debit: string, total_credit: string}
     */
    private function normalizeLines(array $lines, bool $requireBalanced): array
    {
        if (count($lines) < 2) {
            throw ApiException::unprocessable(
                'Jurnal minimal terdiri dari dua baris.',
                'JOURNAL_LINES_REQUIRED',
                ['lines' => ['Tambahkan minimal dua baris jurnal.']],
            );
        }

        $accountIds = array_values(array_unique(array_map(
            static fn (array $line): int => (int) ($line['account_id'] ?? 0),
            $lines,
        )));

        /** @var array<int, Account> $accounts */
        $accounts = Account::query()->whereKey($accountIds)->get()->keyBy('id')->all();

        $normalized = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($lines as $index => $line) {
            $accountId = (int) ($line['account_id'] ?? 0);
            $account = $accounts[$accountId] ?? null;

            if (! $account instanceof Account) {
                throw ApiException::unprocessable(
                    'Akun jurnal tidak ditemukan.',
                    'ACCOUNT_NOT_FOUND',
                    ["lines.{$index}.account_id" => ['Akun tidak ditemukan.']],
                );
            }

            if (! $account->is_postable || ! $account->is_active) {
                throw ApiException::unprocessable(
                    "Akun {$account->code} tidak dapat diposting.",
                    'ACCOUNT_NOT_POSTABLE',
                    ["lines.{$index}.account_id" => ["Akun {$account->code} bukan akun postable/aktif."]],
                );
            }

            $debit = $this->money($line['debit'] ?? null);
            $credit = $this->money($line['credit'] ?? null);

            if (Decimal::isNegative($debit) || Decimal::isNegative($credit)) {
                throw ApiException::unprocessable(
                    'Nilai debit/credit tidak boleh negatif.',
                    'JOURNAL_LINE_INVALID',
                    ["lines.{$index}.debit" => ['Gunakan sisi debit/credit, bukan angka negatif.']],
                );
            }

            $hasDebit = Decimal::compare($debit, '0', Decimal::MONEY_SCALE) > 0;
            $hasCredit = Decimal::compare($credit, '0', Decimal::MONEY_SCALE) > 0;

            if ($hasDebit === $hasCredit) {
                throw ApiException::unprocessable(
                    'Setiap baris harus memiliki tepat satu nilai debit atau credit.',
                    'JOURNAL_LINE_INVALID',
                    ["lines.{$index}.debit" => ['Isi debit atau credit, tidak keduanya.']],
                );
            }

            $totalDebit = Decimal::add($totalDebit, $debit, Decimal::MONEY_SCALE);
            $totalCredit = Decimal::add($totalCredit, $credit, Decimal::MONEY_SCALE);

            $normalized[] = [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'description' => isset($line['description']) ? (string) $line['description'] : null,
                'partner_type' => isset($line['partner_type']) ? (string) $line['partner_type'] : null,
                'partner_id' => isset($line['partner_id']) ? (int) $line['partner_id'] : null,
            ];
        }

        if ($requireBalanced && Decimal::compare($totalDebit, $totalCredit, Decimal::MONEY_SCALE) !== 0) {
            throw ApiException::unprocessable(
                "Jurnal tidak seimbang: debit {$totalDebit} vs credit {$totalCredit}.",
                'JOURNAL_NOT_BALANCED',
                [
                    'total_debit' => [$totalDebit],
                    'total_credit' => [$totalCredit],
                ],
            );
        }

        if (Decimal::compare($totalDebit, '0', Decimal::MONEY_SCALE) <= 0) {
            throw ApiException::unprocessable(
                'Jurnal tidak boleh bernilai nol.',
                'JOURNAL_NOT_BALANCED',
            );
        }

        return [
            'lines' => $normalized,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function insertLines(Journal $journal, array $lines, CarbonImmutable $day): void
    {
        foreach ($lines as $index => $line) {
            $journal->lines()->create([
                ...$line,
                'journal_date' => $day->toDateString(),
                'line_no' => $index + 1,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linesOf(Journal $journal): array
    {
        return $journal->lines()
            ->orderBy('line_no')
            ->orderBy('id')
            ->get()
            ->map(static fn (JournalLine $line): array => [
                'account_id' => $line->account_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'description' => $line->description,
                'partner_type' => $line->partner_type,
                'partner_id' => $line->partner_id,
            ])
            ->all();
    }

    private function lockJournal(Journal $journal): Journal
    {
        /** @var Journal $locked */
        $locked = Journal::query()->lockForUpdate()->findOrFail($journal->getKey());

        return $locked;
    }

    private function loadJournal(Journal $journal): Journal
    {
        return $journal->fresh(['lines.account', 'period']) ?? $journal;
    }

    private function recordPosted(Journal $journal): void
    {
        $this->audit->record($journal, 'posted', [], [
            'journal_no' => $journal->journal_no,
            'journal_date' => $journal->journal_date?->toDateString(),
            'total_debit' => $journal->total_debit,
            'total_credit' => $journal->total_credit,
        ]);
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (! is_string($value) && ! is_int($value)) {
            throw ApiException::unprocessable(
                'Nilai uang harus berupa desimal, bukan float.',
                'JOURNAL_LINE_INVALID',
            );
        }

        return Decimal::round($value, Decimal::MONEY_SCALE);
    }

    /**
     * @param  array{type?: string, id?: int|string|null}|string  $source
     * @return array{type: string, id: int|null}
     */
    private function source(array|string $source): array
    {
        if (is_string($source)) {
            return ['type' => $source, 'id' => null];
        }

        return [
            'type' => (string) ($source['type'] ?? 'manual'),
            'id' => isset($source['id']) ? (int) $source['id'] : null,
        ];
    }

    private function day(CarbonInterface|string $date): CarbonImmutable
    {
        return $date instanceof CarbonInterface
            ? CarbonImmutable::parse($date->toDateString())
            : CarbonImmutable::parse($date);
    }

    private function actorId(): ?int
    {
        $user = app()->bound('request') ? request()->user() : null;
        $id = $user?->getAuthIdentifier() ?? Auth::id();

        return $id === null ? null : (int) $id;
    }
}
