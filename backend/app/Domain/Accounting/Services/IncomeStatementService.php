<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Laba Rugi multi-level dan komparatif.
 *
 * Mengikuti ADR 0004: laba dihitung dinamis dari jurnal posted/reversed tanpa
 * jurnal penutup tahunan. Pendapatan dihitung kredit - debit; HPP dan beban
 * dihitung debit - kredit. Setiap section dikelompokkan berdasarkan akun
 * induk (level) agar tampil sebagai laporan bertingkat.
 */
final class IncomeStatementService
{
    /**
     * @return array<string, mixed>
     */
    public function report(
        string $from,
        string $to,
        ?string $compareFrom = null,
        ?string $compareTo = null,
    ): array {
        $current = $this->section($from, $to);
        $compareFrom ??= CarbonImmutable::parse($from)->subMonthNoOverflow()->startOfMonth()->toDateString();
        $compareTo ??= CarbonImmutable::parse($to)->subMonthNoOverflow()->endOfMonth()->toDateString();
        $previous = $this->section($compareFrom, $compareTo);

        return [
            'from' => $from,
            'to' => $to,
            'sections' => $current['sections'],
            'revenue' => $current['sections']['revenue'],
            'cogs' => $current['sections']['cogs'],
            'expense' => $current['sections']['expense'],
            'gross_profit' => $current['totals']['gross_profit'],
            'operating_profit' => $current['totals']['operating_profit'],
            'net_profit' => $current['totals']['net_profit'],
            'totals' => $current['totals'],
            'comparison' => [
                'from' => $compareFrom,
                'to' => $compareTo,
                'sections' => $previous['sections'],
                'totals' => $previous['totals'],
                'variance' => [
                    'revenue' => $this->variance($current['totals']['revenue'], $previous['totals']['revenue']),
                    'cogs' => $this->variance($current['totals']['cogs'], $previous['totals']['cogs']),
                    'gross_profit' => $this->variance(
                        $current['totals']['gross_profit'],
                        $previous['totals']['gross_profit'],
                    ),
                    'expense' => $this->variance($current['totals']['expense'], $previous['totals']['expense']),
                    'net_profit' => $this->variance(
                        $current['totals']['net_profit'],
                        $previous['totals']['net_profit'],
                    ),
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     sections: array<string, array<string, mixed>>,
     *     totals: array<string, string>
     * }
     */
    private function section(string $from, string $to): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT a.id AS account_id,
                       a.code,
                       a.name,
                       a.type,
                       a.level,
                       a.parent_id,
                       p.code AS parent_code,
                       p.name AS parent_name,
                       COALESCE(SUM(jl.debit), 0) AS debit,
                       COALESCE(SUM(jl.credit), 0) AS credit
                  FROM journal_lines jl
                  JOIN journals j ON j.id = jl.journal_id
                  JOIN accounts a ON a.id = jl.account_id
                  LEFT JOIN accounts p ON p.id = a.parent_id
                 WHERE j.status IN ('posted', 'reversed')
                   AND jl.journal_date BETWEEN ? AND ?
                   AND a.type IN ('revenue', 'cogs', 'expense')
                 GROUP BY a.id, a.code, a.name, a.type, a.level, a.parent_id, p.code, p.name
                 ORDER BY a.code
            SQL,
            [$from, $to],
        );

        $sections = [
            'revenue' => ['groups' => [], 'lines' => [], 'total' => '0.00'],
            'cogs' => ['groups' => [], 'lines' => [], 'total' => '0.00'],
            'expense' => ['groups' => [], 'lines' => [], 'total' => '0.00'],
        ];

        /** @var array<string, array<string, array{code: string, name: string, amount: string}>> $groups */
        $groups = [];

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

            $line = [
                'account_id' => (int) $row->account_id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => $type,
                'level' => (int) $row->level,
                'amount' => $amount,
            ];

            $sections[$type]['lines'][] = $line;
            $sections[$type]['total'] = Decimal::add(
                $sections[$type]['total'],
                $amount,
                Decimal::MONEY_SCALE,
            );

            $groupKey = $row->parent_code === null ? 'LAIN' : (string) $row->parent_code;
            $groups[$type][$groupKey] ??= [
                'code' => $groupKey,
                'name' => $row->parent_name === null ? 'Lain-lain' : (string) $row->parent_name,
                'total' => '0.00',
            ];
            $groups[$type][$groupKey]['total'] = Decimal::add(
                (string) $groups[$type][$groupKey]['total'],
                $amount,
                Decimal::MONEY_SCALE,
            );
        }

        foreach ($sections as $type => &$section) {
            $section['groups'] = array_values($groups[$type] ?? []);
        }

        unset($section);

        $grossProfit = Decimal::sub(
            (string) $sections['revenue']['total'],
            (string) $sections['cogs']['total'],
            Decimal::MONEY_SCALE,
        );
        $operatingProfit = Decimal::sub(
            $grossProfit,
            (string) $sections['expense']['total'],
            Decimal::MONEY_SCALE,
        );

        return [
            'sections' => $sections,
            'totals' => [
                'revenue' => (string) $sections['revenue']['total'],
                'cogs' => (string) $sections['cogs']['total'],
                'gross_profit' => $grossProfit,
                'expense' => (string) $sections['expense']['total'],
                'operating_profit' => $operatingProfit,
                'net_profit' => $operatingProfit,
            ],
        ];
    }

    private function variance(string $current, string $previous): string
    {
        return Decimal::sub($current, $previous, Decimal::MONEY_SCALE);
    }

    private function money(string|int $value): string
    {
        return Decimal::round($value, Decimal::MONEY_SCALE);
    }
}
