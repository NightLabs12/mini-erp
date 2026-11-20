<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Trial Balance: saldo awal, mutasi debit/kredit, dan saldo akhir per akun.
 *
 * Total debit dan kredit harus sama karena seluruh jurnal posted seimbang.
 * Nilai uang dihitung dengan Decimal, bukan float.
 */
final class TrialBalanceService
{
    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     accounts: list<array<string, mixed>>,
     *     totals: array<string, string>,
     *     balanced: bool
     * }
     */
    public function report(string $from, string $to): array
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
                    SELECT jl.account_id,
                           COALESCE(SUM(jl.debit), 0) AS debit,
                           COALESCE(SUM(jl.credit), 0) AS credit
                      FROM journal_lines jl
                      JOIN journals j ON j.id = jl.journal_id
                     WHERE j.status IN ('posted', 'reversed')
                       AND jl.journal_date BETWEEN ? AND ?
                     GROUP BY jl.account_id
                )
                SELECT a.id,
                       a.code,
                       a.name,
                       a.type,
                       a.normal_balance,
                       COALESCE(o.amount, 0) AS opening,
                       COALESCE(p.debit, 0) AS debit,
                       COALESCE(p.credit, 0) AS credit,
                       COALESCE(o.amount, 0) + COALESCE(p.debit, 0) - COALESCE(p.credit, 0) AS closing
                  FROM accounts a
                  LEFT JOIN opening o ON o.account_id = a.id
                  LEFT JOIN period p ON p.account_id = a.id
                 WHERE a.is_postable = true
                   AND a.is_active = true
                   AND (COALESCE(o.amount, 0) <> 0 OR COALESCE(p.debit, 0) <> 0 OR COALESCE(p.credit, 0) <> 0)
                 ORDER BY a.code
            SQL,
            [$from, $from, $to],
        );

        $accounts = [];
        $openingDebit = '0.00';
        $openingCredit = '0.00';
        $movementDebit = '0.00';
        $movementCredit = '0.00';
        $closingDebit = '0.00';
        $closingCredit = '0.00';

        foreach ($rows as $row) {
            $opening = Decimal::round((string) $row->opening, Decimal::MONEY_SCALE);
            $debit = Decimal::round((string) $row->debit, Decimal::MONEY_SCALE);
            $credit = Decimal::round((string) $row->credit, Decimal::MONEY_SCALE);
            $closing = Decimal::round((string) $row->closing, Decimal::MONEY_SCALE);

            $openingDebitAmount = $this->positive($opening);
            $openingCreditAmount = $this->positive(Decimal::mul($opening, '-1', Decimal::MONEY_SCALE));
            $closingDebitAmount = $this->positive($closing);
            $closingCreditAmount = $this->positive(Decimal::mul($closing, '-1', Decimal::MONEY_SCALE));

            $accounts[] = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'normal_balance' => (string) $row->normal_balance,
                'opening_debit' => $openingDebitAmount,
                'opening_credit' => $openingCreditAmount,
                'debit' => $debit,
                'credit' => $credit,
                'closing_debit' => $closingDebitAmount,
                'closing_credit' => $closingCreditAmount,
            ];

            $openingDebit = Decimal::add($openingDebit, $openingDebitAmount, Decimal::MONEY_SCALE);
            $openingCredit = Decimal::add($openingCredit, $openingCreditAmount, Decimal::MONEY_SCALE);
            $movementDebit = Decimal::add($movementDebit, $debit, Decimal::MONEY_SCALE);
            $movementCredit = Decimal::add($movementCredit, $credit, Decimal::MONEY_SCALE);
            $closingDebit = Decimal::add($closingDebit, $closingDebitAmount, Decimal::MONEY_SCALE);
            $closingCredit = Decimal::add($closingCredit, $closingCreditAmount, Decimal::MONEY_SCALE);
        }

        $balanced = Decimal::compare($openingDebit, $openingCredit, Decimal::MONEY_SCALE) === 0
            && Decimal::compare($movementDebit, $movementCredit, Decimal::MONEY_SCALE) === 0
            && Decimal::compare($closingDebit, $closingCredit, Decimal::MONEY_SCALE) === 0;

        return [
            'from' => $from,
            'to' => $to,
            'accounts' => $accounts,
            'totals' => [
                'opening_debit' => $openingDebit,
                'opening_credit' => $openingCredit,
                'debit' => $movementDebit,
                'credit' => $movementCredit,
                'closing_debit' => $closingDebit,
                'closing_credit' => $closingCredit,
            ],
            'balanced' => $balanced,
        ];
    }

    private function positive(string $value): string
    {
        return Decimal::compare($value, '0', Decimal::MONEY_SCALE) <= 0 ? '0.00' : $value;
    }
}
