<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Laba Rugi sederhana untuk periode tertentu.
 *
 * Mengikuti ADR 0004: laba dihitung dinamis dari jurnal posted/reversed tanpa
 * jurnal penutup tahunan. Pendapatan dihitung kredit - debit; HPP dan beban
 * dihitung debit - kredit.
 */
final class IncomeStatementService
{
    /**
     * @return array<string, mixed>
     */
    public function report(string $from, string $to): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT a.id AS account_id,
                       a.code,
                       a.name,
                       a.type,
                       COALESCE(SUM(jl.debit), 0) AS debit,
                       COALESCE(SUM(jl.credit), 0) AS credit
                  FROM journal_lines jl
                  JOIN journals j ON j.id = jl.journal_id
                  JOIN accounts a ON a.id = jl.account_id
                 WHERE j.status IN ('posted', 'reversed')
                   AND jl.journal_date BETWEEN ? AND ?
                   AND a.type IN ('revenue', 'cogs', 'expense')
                 GROUP BY a.id, a.code, a.name, a.type
                 ORDER BY a.code
            SQL,
            [$from, $to],
        );

        $sections = [
            'revenue' => [],
            'cogs' => [],
            'expense' => [],
        ];
        $totals = [
            'revenue' => '0.00',
            'cogs' => '0.00',
            'expense' => '0.00',
        ];

        foreach ($rows as $row) {
            $type = (string) $row->type;
            $debit = $this->money((string) $row->debit);
            $credit = $this->money((string) $row->credit);
            $amount = $type === 'revenue'
                ? Decimal::sub($credit, $debit, Decimal::MONEY_SCALE)
                : Decimal::sub($debit, $credit, Decimal::MONEY_SCALE);

            if (Decimal::compare($amount, '0', Decimal::MONEY_SCALE) === 0) {
                continue;
            }

            $sections[$type][] = [
                'account_id' => (int) $row->account_id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => $type,
                'amount' => $amount,
            ];
            $totals[$type] = Decimal::add($totals[$type], $amount, Decimal::MONEY_SCALE);
        }

        $grossProfit = Decimal::sub($totals['revenue'], $totals['cogs'], Decimal::MONEY_SCALE);
        $netProfit = Decimal::sub($grossProfit, $totals['expense'], Decimal::MONEY_SCALE);

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => [
                'lines' => $sections['revenue'],
                'total' => $totals['revenue'],
            ],
            'cogs' => [
                'lines' => $sections['cogs'],
                'total' => $totals['cogs'],
            ],
            'expense' => [
                'lines' => $sections['expense'],
                'total' => $totals['expense'],
            ],
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
        ];
    }

    private function money(string|int $value): string
    {
        return Decimal::round($value, Decimal::MONEY_SCALE);
    }
}
