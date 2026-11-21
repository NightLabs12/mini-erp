<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Core\Models\AccountSetting;
use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Kas/Bank: saldo awal, mutasi harian, saldo berjalan, dan saldo akhir
 * untuk akun kas dan bank default.
 */
final class CashBankReportService
{
    /**
     * @return array<string, mixed>
     */
    public function report(string $from, string $to): array
    {
        $accountIds = AccountSetting::query()
            ->whereIn('key', [AccountMapper::CASH_DEFAULT, AccountMapper::BANK_DEFAULT])
            ->whereNotNull('account_id')
            ->pluck('account_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        /** @var \Illuminate\Support\Collection<int, Account> $accounts */
        $accounts = Account::query()
            ->whereKey($accountIds)
            ->orderBy('code')
            ->get()
            ->keyBy(static fn (Account $account): int => (int) $account->getKey());

        $result = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';
        $totalClosing = '0.00';

        foreach ($accounts as $account) {
            $opening = DB::selectOne(
                <<<'SQL'
                    SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS amount
                      FROM journal_lines jl
                      JOIN journals j ON j.id = jl.journal_id
                     WHERE j.status IN ('posted', 'reversed')
                       AND jl.journal_date < ?
                       AND jl.account_id = ?
                SQL,
                [$from, $account->getKey()],
            );

            $openingBalance = $this->money((string) ($opening->amount ?? '0'));

            $rows = DB::select(
                <<<'SQL'
                    SELECT jl.id,
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
                       AND jl.account_id = ?
                     ORDER BY jl.journal_date, jl.id
                SQL,
                [$from, $to, $account->getKey()],
            );

            $running = $openingBalance;
            $lines = [];

            foreach ($rows as $row) {
                $debit = $this->money((string) $row->debit);
                $credit = $this->money((string) $row->credit);
                $running = Decimal::add(
                    Decimal::sub($running, $credit, Decimal::MONEY_SCALE),
                    $debit,
                    Decimal::MONEY_SCALE,
                );

                $lines[] = [
                    'id' => (int) $row->id,
                    'journal_date' => (string) $row->journal_date,
                    'journal_no' => $row->journal_no === null ? null : (string) $row->journal_no,
                    'description' => $row->description === null ? null : (string) $row->description,
                    'journal_description' => $row->journal_description === null ? null : (string) $row->journal_description,
                    'debit' => $debit,
                    'credit' => $credit,
                    'running_balance' => $running,
                ];
            }

            $totalClosing = Decimal::add($totalClosing, $running, Decimal::MONEY_SCALE);

            $result[] = [
                'account' => [
                    'id' => (int) $account->getKey(),
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                ],
                'opening_balance' => $openingBalance,
                'total_debit' => $this->sumLines($lines, 'debit'),
                'total_credit' => $this->sumLines($lines, 'credit'),
                'closing_balance' => $running,
                'lines' => $lines,
            ];

            $totalDebit = Decimal::add($totalDebit, $this->sumLines($lines, 'debit'), Decimal::MONEY_SCALE);
            $totalCredit = Decimal::add($totalCredit, $this->sumLines($lines, 'credit'), Decimal::MONEY_SCALE);
        }

        return [
            'from' => $from,
            'to' => $to,
            'accounts' => $result,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'closing_balance' => $totalClosing,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function sumLines(array $lines, string $field): string
    {
        $total = '0.00';

        foreach ($lines as $line) {
            $total = Decimal::add($total, (string) $line[$field], Decimal::MONEY_SCALE);
        }

        return $total;
    }

    private function money(string|int $value): string
    {
        return Decimal::round($value, Decimal::MONEY_SCALE);
    }
}
