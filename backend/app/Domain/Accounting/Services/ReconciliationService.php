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
 * Empat pemeriksaan:
 * 1. Hutang Usaha GL = sisa purchase invoice approved.
 * 2. Piutang Usaha GL = sisa sales invoice approved.
 * 3. Persediaan GL = SUM stock_balances.value.
 * 4. GR/IR GL = nilai goods receipt approved yang belum ditagih.
 *
 * Pemeriksaan hanya dijalankan bila tabel sumbernya ada. Hasilnya dipakai
 * laporan rekonsiliasi dan validasi tutup periode.
 */
final class ReconciliationService
{
    /**
     * @return array{as_of: string, checks: list<array<string, string>>, ok: bool}
     */
    public function report(string $asOf): array
    {
        $checks = [];

        $this->compare(
            $checks,
            'accounts_payable',
            'Hutang Usaha',
            $this->signedBalance(['accounts_payable'], $asOf),
            $this->outstanding('purchase_invoices', $asOf),
            false,
        );

        $this->compare(
            $checks,
            'accounts_receivable',
            'Piutang Usaha',
            $this->signedBalance(['accounts_receivable'], $asOf),
            $this->outstanding('sales_invoices', $asOf),
            true,
        );

        $this->compare(
            $checks,
            'inventory',
            'Persediaan',
            $this->signedBalance([
                AccountMapper::INVENTORY_TRADING,
                AccountMapper::INVENTORY_RAW,
                AccountMapper::INVENTORY_FINISHED,
            ], $asOf),
            $this->stockValue($asOf),
            true,
        );

        $this->compare(
            $checks,
            'grir',
            'GR/IR',
            $this->signedBalance([AccountMapper::GRIR], $asOf),
            $this->grirOutstanding($asOf),
            false,
        );

        $ok = true;

        foreach ($checks as $check) {
            if (($check['status'] ?? 'OK') === 'MISMATCH') {
                $ok = false;

                break;
            }
        }

        return [
            'as_of' => $asOf,
            'checks' => $checks,
            'ok' => $ok,
        ];
    }

    /**
     * @return array{as_of: string, checks: list<array<string, string>>, ok: bool}
     */
    public function reportForPeriod(AccountingPeriod $period): array
    {
        return $this->report($period->lastDate()->toDateString());
    }

    /**
     * @return list<array{check: string, expected: string, actual: string, difference: string}>
     */
    public function mismatches(AccountingPeriod $period): array
    {
        return array_values(array_filter(
            $this->reportForPeriod($period)['checks'],
            static fn (array $check): bool => ($check['status'] ?? 'OK') === 'MISMATCH',
        ));
    }

    /**
     * @param  list<array<string, string>>  $checks
     */
    private function compare(
        array &$checks,
        string $check,
        string $label,
        ?string $actual,
        ?string $source,
        bool $sourceIsDebit,
    ): void {
        if ($actual === null || $source === null) {
            $checks[] = [
                'check' => $check,
                'label' => $label,
                'expected' => '0.00',
                'actual' => '0.00',
                'difference' => '0.00',
                'status' => 'SKIPPED',
            ];

            return;
        }

        $expected = $sourceIsDebit
            ? Decimal::round($source, Decimal::MONEY_SCALE)
            : Decimal::round(Decimal::mul($source, '-1', Decimal::MONEY_SCALE), Decimal::MONEY_SCALE);

        $difference = Decimal::sub($expected, $actual, Decimal::MONEY_SCALE);
        $status = Decimal::compare($expected, $actual, Decimal::MONEY_SCALE) === 0 ? 'OK' : 'MISMATCH';

        $checks[] = [
            'check' => $check,
            'label' => $label,
            'expected' => $expected,
            'actual' => $actual,
            'difference' => $difference,
            'status' => $status,
        ];
    }

    /**
     * Saldo signed (debit - credit) akun pada tanggal tertentu.
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

    /**
     * Sisa tagihan per tanggal laporan.
     *
     * Nilai dihitung dari total invoice dikurangi alokasi pembayaran yang
     * benar-benar sudah approved dan bertanggal <= as-of. Memakai kolom
     * `paid_amount` saja akan salah bila ada pembayaran bertanggal setelah
     * as-of tetapi sudah di-approve, sehingga GL (yang memfilter journal_date)
     * dan laporan ini dapat berbeda tanpa sebab.
     */
    private function outstanding(string $table, string $asOf): ?string
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumns($table, ['status', 'doc_date', 'total', 'paid_amount'])) {
            return null;
        }

        $deletedFilter = Schema::hasColumn($table, 'deleted_at') ? ' AND i.deleted_at IS NULL' : '';

        $allocation = match ($table) {
            'purchase_invoices' => [
                'table' => 'supplier_payment_allocations',
                'invoice_column' => 'purchase_invoice_id',
                'payment_table' => 'supplier_payments',
                'payment_column' => 'supplier_payment_id',
            ],
            'sales_invoices' => [
                'table' => 'customer_payment_allocations',
                'invoice_column' => 'sales_invoice_id',
                'payment_table' => 'customer_payments',
                'payment_column' => 'customer_payment_id',
            ],
            default => null,
        };

        if ($allocation === null
            || ! Schema::hasTable($allocation['table'])
            || ! Schema::hasTable($allocation['payment_table'])) {
            return $this->legacyOutstanding($table, $asOf, $deletedFilter);
        }

        $allocations = $allocation['table'];
        $invoiceColumn = $allocation['invoice_column'];
        $paymentTable = $allocation['payment_table'];
        $paymentColumn = $allocation['payment_column'];

        $row = DB::selectOne(
            "SELECT COALESCE(SUM(i.total - COALESCE(a.amount, 0)), 0) AS amount
               FROM {$table} i
               LEFT JOIN (
                   SELECT al.{$invoiceColumn} AS invoice_id, SUM(al.amount) AS amount
                     FROM {$allocations} al
                     JOIN {$paymentTable} p ON p.id = al.{$paymentColumn}
                    WHERE p.status = 'approved'
                      AND p.deleted_at IS NULL
                      AND p.doc_date <= ?
                    GROUP BY al.{$invoiceColumn}
               ) a ON a.invoice_id = i.id
              WHERE i.status = 'approved'
                AND i.doc_date <= ?{$deletedFilter}",
            [$asOf, $asOf],
        );

        return Decimal::round((string) ($row->amount ?? '0'), Decimal::MONEY_SCALE);
    }

    /**
     * Fallback bila tabel pembayaran belum tersedia (mis. migrasi lama).
     */
    private function legacyOutstanding(string $table, string $asOf, string $deletedFilter): string
    {
        $row = DB::selectOne(
            "SELECT COALESCE(SUM(i.total - i.paid_amount), 0) AS amount
               FROM {$table} i
              WHERE i.status = 'approved'
                AND i.doc_date <= ?{$deletedFilter}",
            [$asOf],
        );

        return Decimal::round((string) ($row->amount ?? '0'), Decimal::MONEY_SCALE);
    }

    /**
     * Nilai persediaan per tanggal dari ledger. Untuk tanggal hari ini nilainya
     * sama dengan SUM stock_balances.value, tetapi perhitungan ini tetap benar
     * saat menutup periode lama.
     */
    private function stockValue(string $asOf): ?string
    {
        if (! Schema::hasTable('stock_ledger')
            || ! Schema::hasColumn('stock_ledger', 'total_value')) {
            return null;
        }

        $row = DB::selectOne(
            <<<'SQL'
                SELECT COALESCE(SUM(
                           CASE WHEN qty_in_base > 0 THEN total_value ELSE -total_value END
                       ), 0) AS amount
                  FROM stock_ledger
                 WHERE movement_date <= ?
            SQL,
            [$asOf],
        );

        return Decimal::round((string) ($row->amount ?? '0'), Decimal::MONEY_SCALE);
    }

    private function grirOutstanding(string $asOf): ?string
    {
        if (! Schema::hasTable('goods_receipt_items')
            || ! Schema::hasTable('goods_receipts')
            || ! Schema::hasColumns('goods_receipt_items', ['qty_base', 'invoiced_qty_base', 'unit_cost_base'])) {
            return null;
        }

        $row = DB::selectOne(
            <<<'SQL'
                SELECT COALESCE(SUM(
                           (gri.qty_base - gri.invoiced_qty_base) * gri.unit_cost_base
                       ), 0) AS amount
                  FROM goods_receipt_items gri
                  JOIN goods_receipts gr ON gr.id = gri.goods_receipt_id
                 WHERE gr.status = 'approved'
                   AND gr.deleted_at IS NULL
                   AND gr.doc_date <= ?
            SQL,
            [$asOf],
        );

        return Decimal::round((string) ($row->amount ?? '0'), Decimal::MONEY_SCALE);
    }
}
