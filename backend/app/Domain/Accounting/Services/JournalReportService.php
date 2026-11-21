<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Jurnal: daftar jurnal posted/reversed pada rentang tanggal beserta
 * baris dan totalnya. Filter opsional berdasarkan source_type dan status.
 */
final class JournalReportService
{
    /**
     * @return array<string, mixed>
     */
    public function report(
        string $from,
        string $to,
        ?string $sourceType = null,
        ?string $status = null,
    ): array {
        $bindings = [$from, $to];
        $conditions = ['j.journal_date BETWEEN ? AND ?'];

        if ($sourceType !== null && $sourceType !== '') {
            $conditions[] = 'j.source_type = ?';
            $bindings[] = $sourceType;
        }

        if ($status !== null && $status !== '') {
            $conditions[] = 'j.status = ?';
            $bindings[] = $status;
        }

        $where = implode(' AND ', $conditions);

        $rows = DB::select(
            "SELECT j.id AS journal_id,
                    j.journal_no,
                    j.journal_date,
                    j.source_type,
                    j.source_id,
                    j.description,
                    j.status,
                    j.total_debit,
                    j.total_credit,
                    jl.id AS line_id,
                    jl.line_no,
                    jl.account_id,
                    a.code AS account_code,
                    a.name AS account_name,
                    jl.debit,
                    jl.credit,
                    jl.description AS line_description
               FROM journals j
               JOIN journal_lines jl ON jl.journal_id = j.id
               JOIN accounts a ON a.id = jl.account_id
              WHERE {$where}
                AND j.deleted_at IS NULL
              ORDER BY j.journal_date, j.id, jl.line_no, jl.id",
            $bindings,
        );

        /** @var array<int, array<string, mixed>> $journals */
        $journals = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($rows as $row) {
            $id = (int) $row->journal_id;

            if (! isset($journals[$id])) {
                $journals[$id] = [
                    'id' => $id,
                    'journal_no' => $row->journal_no === null ? null : (string) $row->journal_no,
                    'journal_date' => (string) $row->journal_date,
                    'source_type' => $row->source_type === null ? null : (string) $row->source_type,
                    'source_id' => $row->source_id === null ? null : (int) $row->source_id,
                    'description' => $row->description === null ? null : (string) $row->description,
                    'status' => (string) $row->status,
                    'total_debit' => $this->money((string) $row->total_debit),
                    'total_credit' => $this->money((string) $row->total_credit),
                    'lines' => [],
                ];
            }

            $journals[$id]['lines'][] = [
                'id' => (int) $row->line_id,
                'line_no' => (int) $row->line_no,
                'account_id' => (int) $row->account_id,
                'account_code' => (string) $row->account_code,
                'account_name' => (string) $row->account_name,
                'debit' => $this->money((string) $row->debit),
                'credit' => $this->money((string) $row->credit),
                'description' => $row->line_description === null ? null : (string) $row->line_description,
            ];

            $totalDebit = Decimal::add($totalDebit, $this->money((string) $row->debit), Decimal::MONEY_SCALE);
            $totalCredit = Decimal::add($totalCredit, $this->money((string) $row->credit), Decimal::MONEY_SCALE);
        }

        return [
            'from' => $from,
            'to' => $to,
            'journals' => array_values($journals),
            'totals' => [
                'count' => count($journals),
                'debit' => $totalDebit,
                'credit' => $totalCredit,
            ],
        ];
    }

    private function money(string|int $value): string
    {
        return Decimal::round($value, Decimal::MONEY_SCALE);
    }
}
