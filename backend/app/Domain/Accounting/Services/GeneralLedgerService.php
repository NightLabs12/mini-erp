<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * General Ledger: saldo awal, mutasi, saldo berjalan, dan saldo akhir.
 *
 * Query memakai CTE opening/period dan window function SUM(...) OVER
 * (PARTITION BY account_id ORDER BY journal_date, id). Seluruh nilai dari
 * request masuk sebagai parameter binding, tidak pernah dirangkai ke SQL.
 */
final class GeneralLedgerService
{
    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     accounts: list<array<string, mixed>>,
     *     totals: array{debit: string, credit: string}
     * }
     */
    public function report(?int $accountId, string $from, string $to): array
    {
        $rows = DB::select(
            <<<'SQL'
                WITH opening AS (
                    SELECT jl.account_id,
                           COALESCE(SUM(jl.debit - jl.credit), 0) AS amount
                      FROM journal_lines jl
                      JOIN journals j ON j.id = jl.journal_id
                     WHERE j.status IN ('posted', 'reversed')
                       AND jl.journal_date < ?
                     GROUP BY jl.account_id
                ),
                period AS (
                    SELECT jl.id,
                           jl.account_id,
                           jl.journal_date,
                           jl.debit,
                           jl.credit,
                           jl.description,
                           j.journal_no,
                           j.description AS journal_description
                      FROM journal_lines jl
                      JOIN journals j ON j.id = jl.journal_id
                     WHERE j.status IN ('posted', 'reversed')
                       AND jl.journal_date BETWEEN ? AND ?
                )
                SELECT a.id AS account_id,
                       a.code AS account_code,
                       a.name AS account_name,
                       a.type,
                       a.normal_balance,
                       COALESCE(o.amount, 0) AS opening_balance,
                       p.id AS line_id,
                       p.journal_date,
                       p.journal_no,
                       p.description AS line_description,
                       p.journal_description,
                       p.debit,
                       p.credit,
                       CASE
                           WHEN p.id IS NULL THEN NULL
                           ELSE COALESCE(o.amount, 0) + SUM(p.debit - p.credit) OVER (
                               PARTITION BY a.id
                               ORDER BY p.journal_date, p.id
                               ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                           )
                       END AS running_balance
                  FROM accounts a
                  LEFT JOIN opening o ON o.account_id = a.id
                  LEFT JOIN period p ON p.account_id = a.id
                 WHERE a.is_postable = true
                   AND a.is_active = true
                   AND (CAST(? AS bigint) IS NULL OR a.id = CAST(? AS bigint))
                   AND (COALESCE(o.amount, 0) <> 0 OR p.id IS NOT NULL)
                 ORDER BY a.code, p.journal_date, p.id
            SQL,
            [$from, $from, $to, $accountId, $accountId],
        );

        /** @var array<int, array<string, mixed>> $accounts */
        $accounts = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($rows as $row) {
            $id = (int) $row->account_id;

            if (! isset($accounts[$id])) {
                $accounts[$id] = [
                    'account' => [
                        'id' => $id,
                        'code' => (string) $row->account_code,
                        'name' => (string) $row->account_name,
                        'type' => (string) $row->type,
                        'normal_balance' => (string) $row->normal_balance,
                    ],
                    'opening_balance' => Decimal::round((string) $row->opening_balance, Decimal::MONEY_SCALE),
                    'total_debit' => '0.00',
                    'total_credit' => '0.00',
                    'closing_balance' => '0.00',
                    'lines' => [],
                ];
            }

            if ($row->line_id === null) {
                continue;
            }

            $debit = Decimal::round((string) $row->debit, Decimal::MONEY_SCALE);
            $credit = Decimal::round((string) $row->credit, Decimal::MONEY_SCALE);

            $accounts[$id]['total_debit'] = Decimal::add(
                (string) $accounts[$id]['total_debit'],
                $debit,
                Decimal::MONEY_SCALE,
            );
            $accounts[$id]['total_credit'] = Decimal::add(
                (string) $accounts[$id]['total_credit'],
                $credit,
                Decimal::MONEY_SCALE,
            );

            $accounts[$id]['lines'][] = [
                'id' => (int) $row->line_id,
                'journal_date' => (string) $row->journal_date,
                'journal_no' => $row->journal_no === null ? null : (string) $row->journal_no,
                'description' => $row->line_description === null ? null : (string) $row->line_description,
                'journal_description' => $row->journal_description === null ? null : (string) $row->journal_description,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => Decimal::round((string) $row->running_balance, Decimal::MONEY_SCALE),
            ];

            $totalDebit = Decimal::add($totalDebit, $debit, Decimal::MONEY_SCALE);
            $totalCredit = Decimal::add($totalCredit, $credit, Decimal::MONEY_SCALE);
        }

        foreach ($accounts as &$account) {
            $account['closing_balance'] = Decimal::add(
                Decimal::sub(
                    (string) $account['opening_balance'],
                    (string) $account['total_credit'],
                    Decimal::MONEY_SCALE,
                ),
                (string) $account['total_debit'],
                Decimal::MONEY_SCALE,
            );
        }

        unset($account);

        return [
            'from' => $from,
            'to' => $to,
            'accounts' => array_values($accounts),
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
            ],
        ];
    }
}
