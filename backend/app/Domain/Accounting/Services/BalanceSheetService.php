<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Neraca komparatif dua periode dengan laba tahun berjalan dinamis.
 *
 * Aset, kewajiban, dan ekuitas diambil dari saldo akun postable per tanggal.
 * Laba tahun berjalan dihitung dari jurnal pendapatan/HPP/beban sejak awal
 * tahun buku sampai tanggal laporan, lalu ditambahkan sebagai baris ekuitas
 * sintetis 3-3000. Tidak ada jurnal penutup tahunan (ADR 0004).
 */
final class BalanceSheetService
{
    /**
     * @return array<string, mixed>
     */
    public function report(string $asOf, ?string $compareAsOf = null): array
    {
        $compareAsOf ??= CarbonImmutable::parse($asOf)->subMonthNoOverflow()->endOfMonth()->toDateString();

        $current = $this->snapshot($asOf);
        $previous = $this->snapshot($compareAsOf);

        return [
            'as_of' => $asOf,
            'assets' => $current['assets'],
            'liabilities' => $current['liabilities'],
            'equity' => $current['equity'],
            'totals' => $current['totals'],
            'balanced' => $current['balanced'],
            'comparison' => [
                'as_of' => $compareAsOf,
                'assets' => $previous['assets'],
                'liabilities' => $previous['liabilities'],
                'equity' => $previous['equity'],
                'totals' => $previous['totals'],
                'balanced' => $previous['balanced'],
                'variance' => [
                    'total_assets' => Decimal::sub(
                        $current['totals']['total_assets'],
                        $previous['totals']['total_assets'],
                        Decimal::MONEY_SCALE,
                    ),
                    'total_liabilities' => Decimal::sub(
                        $current['totals']['total_liabilities'],
                        $previous['totals']['total_liabilities'],
                        Decimal::MONEY_SCALE,
                    ),
                    'total_equity' => Decimal::sub(
                        $current['totals']['total_equity'],
                        $previous['totals']['total_equity'],
                        Decimal::MONEY_SCALE,
                    ),
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     assets: list<array<string, mixed>>,
     *     liabilities: list<array<string, mixed>>,
     *     equity: list<array<string, mixed>>,
     *     totals: array<string, string>,
     *     balanced: bool
     * }
     */
    private function snapshot(string $asOf): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT a.id,
                       a.code,
                       a.name,
                       a.type,
                       a.normal_balance,
                       a.level,
                       COALESCE(b.amount, 0) AS amount
                  FROM accounts a
                  LEFT JOIN (
                      SELECT jl.account_id,
                             COALESCE(SUM(jl.debit - jl.credit), 0) AS amount
                        FROM journal_lines jl
                        JOIN journals j ON j.id = jl.journal_id
                       WHERE j.status IN ('posted', 'reversed')
                         AND jl.journal_date <= ?
                       GROUP BY jl.account_id
                  ) b ON b.account_id = a.id
                 WHERE a.is_postable = true
                   AND a.is_active = true
                   AND a.type IN ('asset', 'liability', 'equity')
                 ORDER BY a.code
            SQL,
            [$asOf],
        );

        $assets = [];
        $liabilities = [];
        $equity = [];

        foreach ($rows as $row) {
            $signed = Decimal::round((string) $row->amount, Decimal::MONEY_SCALE);
            $type = (string) $row->type;

            $amount = match ($type) {
                'asset' => $signed,
                default => Decimal::mul($signed, '-1', Decimal::MONEY_SCALE),
            };

            if (Decimal::compare($amount, '0', Decimal::MONEY_SCALE) === 0) {
                continue;
            }

            $line = [
                'account_id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => $type,
                'normal_balance' => (string) $row->normal_balance,
                'level' => (int) $row->level,
                'amount' => $amount,
            ];

            match ($type) {
                'asset' => $assets[] = $line,
                'liability' => $liabilities[] = $line,
                'equity' => $equity[] = $line,
                default => null,
            };
        }

        $currentProfit = $this->currentYearProfit($asOf);

        if (Decimal::compare($currentProfit, '0', Decimal::MONEY_SCALE) !== 0) {
            $equity[] = [
                'account_id' => null,
                'code' => '3-3000',
                'name' => 'Laba Tahun Berjalan',
                'type' => 'equity',
                'normal_balance' => 'C',
                'level' => 2,
                'amount' => $currentProfit,
                'dynamic' => true,
            ];
        }

        $totalAssets = $this->sum($assets);
        $totalLiabilities = $this->sum($liabilities);
        $totalEquity = $this->sum($equity);
        $totalLiabilitiesEquity = Decimal::add($totalLiabilities, $totalEquity, Decimal::MONEY_SCALE);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totals' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'total_liabilities_equity' => $totalLiabilitiesEquity,
                'current_year_profit' => $currentProfit,
            ],
            'balanced' => Decimal::compare($totalAssets, $totalLiabilitiesEquity, Decimal::MONEY_SCALE) === 0,
        ];
    }

    private function currentYearProfit(string $asOf): string
    {
        $start = CarbonImmutable::parse($asOf)->startOfYear()->toDateString();

        $rows = DB::select(
            <<<'SQL'
                SELECT a.type,
                       COALESCE(SUM(jl.debit), 0) AS debit,
                       COALESCE(SUM(jl.credit), 0) AS credit
                  FROM journal_lines jl
                  JOIN journals j ON j.id = jl.journal_id
                  JOIN accounts a ON a.id = jl.account_id
                 WHERE j.status IN ('posted', 'reversed')
                   AND jl.journal_date BETWEEN ? AND ?
                   AND a.type IN ('revenue', 'cogs', 'expense')
                 GROUP BY a.type
            SQL,
            [$start, $asOf],
        );

        $revenue = '0.00';
        $costs = '0.00';

        foreach ($rows as $row) {
            $debit = $this->money((string) $row->debit);
            $credit = $this->money((string) $row->credit);

            if ($row->type === 'revenue') {
                $revenue = Decimal::add($revenue, Decimal::sub($credit, $debit, Decimal::MONEY_SCALE), Decimal::MONEY_SCALE);

                continue;
            }

            $costs = Decimal::add($costs, Decimal::sub($debit, $credit, Decimal::MONEY_SCALE), Decimal::MONEY_SCALE);
        }

        return Decimal::sub($revenue, $costs, Decimal::MONEY_SCALE);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function sum(array $lines): string
    {
        $total = '0.00';

        foreach ($lines as $line) {
            $total = Decimal::add($total, (string) $line['amount'], Decimal::MONEY_SCALE);
        }

        return $total;
    }

    private function money(string|int $value): string
    {
        return Decimal::round($value, Decimal::MONEY_SCALE);
    }
}
