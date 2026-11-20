<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Core\Models\AccountSetting;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rekonsiliasi GL dengan sumber dokumen (SPEC 3.9).
 *
 * Pemeriksaan hanya dijalankan untuk tabel yang sudah ada. Pada Fase 3 tabel
 * dokumen purchasing/sales/inventory belum dibuat, sehingga hasilnya kosong
 * dan tutup periode tidak terhalang. Begitu tabelnya hadir, pemeriksaan
 * otomatis ikut aktif tanpa mengubah PeriodClosingService.
 */
final class ReconciliationService
{
    /**
     * @return list<array{check: string, expected: string, actual: string, difference: string}>
     */
    public function mismatches(AccountingPeriod $period): array
    {
        $asOf = $period->lastDate()->toDateString();
        $mismatches = [];

        $this->compare(
            $mismatches,
            'accounts_payable',
            $this->signedBalance(['accounts_payable'], $asOf),
            $this->outstanding('purchase_invoices', $asOf),
            false,
        );

        $this->compare(
            $mismatches,
            'accounts_receivable',
            $this->signedBalance(['accounts_receivable'], $asOf),
            $this->outstanding('sales_invoices', $asOf),
            true,
        );

        $this->compare(
            $mismatches,
            'inventory',
            $this->signedBalance([
                AccountMapper::INVENTORY_TRADING,
                AccountMapper::INVENTORY_RAW,
                AccountMapper::INVENTORY_FINISHED,
            ], $asOf),
            $this->stockValue(),
            true,
        );

        return $mismatches;
    }

    /**
     * @param  list<array{check: string, expected: string, actual: string, difference: string}>  $mismatches
     */
    private function compare(
        array &$mismatches,
        string $check,
        ?string $actual,
        ?string $source,
        bool $sourceIsDebit,
    ): void {
        if ($actual === null || $source === null) {
            return;
        }

        $expected = $sourceIsDebit
            ? Decimal::round($source, Decimal::MONEY_SCALE)
            : Decimal::round(Decimal::mul($source, '-1', Decimal::MONEY_SCALE), Decimal::MONEY_SCALE);

        if (Decimal::compare($expected, $actual, Decimal::MONEY_SCALE) === 0) {
            return;
        }

        $mismatches[] = [
            'check' => $check,
            'expected' => $expected,
            'actual' => $actual,
            'difference' => Decimal::sub($expected, $actual, Decimal::MONEY_SCALE),
        ];
    }

    /**
     * Saldo signed (debit - credit) akun pada akhir periode.
     *
     * @param  list<string>  $keys
     */
    private function signedBalance(array $keys, string $asOf): ?string
    {
        $accountIds = AccountSetting::query()
            ->whereIn('key', $keys)
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($accountIds === []) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($accountIds), '?'));

        $row = DB::selectOne(
            "SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS amount
               FROM journal_lines jl
               JOIN journals j ON j.id = jl.journal_id
              WHERE j.status IN ('posted', 'reversed')
                AND jl.journal_date <= ?
                AND jl.account_id IN ({$placeholders})",
            [$asOf, ...$accountIds],
        );

        return Decimal::round((string) ($row->amount ?? '0'), Decimal::MONEY_SCALE);
    }

    private function outstanding(string $table, string $asOf): ?string
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumns($table, ['status', 'doc_date', 'total', 'paid_amount'])) {
            return null;
        }

        $deletedFilter = Schema::hasColumn($table, 'deleted_at') ? ' AND deleted_at IS NULL' : '';

        $row = DB::selectOne(
            "SELECT COALESCE(SUM(total - paid_amount), 0) AS amount
               FROM {$table}
              WHERE status = 'approved'
                AND doc_date <= ?{$deletedFilter}",
            [$asOf],
        );

        return Decimal::round((string) ($row->amount ?? '0'), Decimal::MONEY_SCALE);
    }

    private function stockValue(): ?string
    {
        if (! Schema::hasTable('stock_balances')
            || ! Schema::hasColumn('stock_balances', 'value')) {
            return null;
        }

        $deletedFilter = Schema::hasColumn('stock_balances', 'deleted_at') ? ' WHERE deleted_at IS NULL' : '';

        $row = DB::selectOne("SELECT COALESCE(SUM(value), 0) AS amount FROM stock_balances{$deletedFilter}");

        return Decimal::round((string) ($row->amount ?? '0'), Decimal::MONEY_SCALE);
    }
}
