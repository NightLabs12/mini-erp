<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Exports\GenericReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satu abstraksi ekspor untuk seluruh laporan.
 *
 * Setiap laporan dinormalisasi menjadi {title, headings, rows, meta} lalu
 * ditulis ke Excel (Maatwebsite) atau PDF (DomPDF). JSON memakai data asli
 * tanpa normalisasi.
 */
final class ReportExporter
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function toExcel(string $code, array $report): BinaryFileResponse
    {
        $sheet = $this->sheet($code, $report);

        return Excel::download(
            new GenericReportExport($sheet['title'], $sheet['headings'], $sheet['rows']),
            $this->filename($code, 'xlsx'),
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function toPdf(string $code, array $report): Response
    {
        $sheet = $this->sheet($code, $report);

        return Pdf::loadView('reports.generic', [
            'title' => $sheet['title'],
            'headings' => $sheet['headings'],
            'rows' => $sheet['rows'],
            'meta' => $sheet['meta'],
        ])->setPaper('a4', 'landscape')->download($this->filename($code, 'pdf'));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{title: string, headings: list<string>, rows: list<list<mixed>>, meta: list<array{label: string, value: string}>}
     */
    public function sheet(string $code, array $report): array
    {
        $title = ReportRegistry::TITLES[$code] ?? $code;
        $meta = $this->meta($report);

        [$headings, $rows] = match ($code) {
            'general-ledger' => $this->generalLedgerRows($report),
            'trial-balance' => $this->trialBalanceRows($report),
            'income-statement' => $this->incomeStatementRows($report),
            'balance-sheet' => $this->balanceSheetRows($report),
            'cash-bank' => $this->cashBankRows($report),
            'journal' => $this->journalRows($report),
            'stock-card' => $this->stockCardRows($report),
            'stock-position' => $this->stockPositionRows($report),
            'stock-movement' => $this->stockMovementRows($report),
            'ap-aging', 'ar-aging' => $this->agingRows($report),
            'ap-outstanding', 'ar-outstanding' => $this->outstandingRows($report),
            'sales-summary', 'purchase-summary' => $this->summaryRows($report),
            'so-outstanding', 'po-outstanding' => $this->orderOutstandingRows($report),
            'reconciliation' => $this->reconciliationRows($report),
            default => $this->genericRows($report),
        };

        return [
            'title' => $title,
            'headings' => $headings,
            'rows' => $rows,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function generalLedgerRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'accounts') as $account) {
            $info = $this->array($account['account'] ?? []);

            $rows[] = [
                (string) ($info['code'] ?? ''),
                (string) ($info['name'] ?? ''),
                (string) ($info['type'] ?? ''),
                (string) ($account['opening_balance'] ?? '0.00'),
                (string) ($account['total_debit'] ?? '0.00'),
                (string) ($account['total_credit'] ?? '0.00'),
                (string) ($account['closing_balance'] ?? '0.00'),
            ];
        }

        return [['Kode', 'Nama Akun', 'Tipe', 'Saldo Awal', 'Debit', 'Kredit', 'Saldo Akhir'], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function trialBalanceRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'accounts') as $account) {
            $rows[] = [
                (string) ($account['code'] ?? ''),
                (string) ($account['name'] ?? ''),
                (string) ($account['opening_debit'] ?? '0.00'),
                (string) ($account['opening_credit'] ?? '0.00'),
                (string) ($account['debit'] ?? '0.00'),
                (string) ($account['credit'] ?? '0.00'),
                (string) ($account['closing_debit'] ?? '0.00'),
                (string) ($account['closing_credit'] ?? '0.00'),
            ];
        }

        return [[
            'Kode', 'Nama Akun', 'Awal Debit', 'Awal Kredit',
            'Mutasi Debit', 'Mutasi Kredit', 'Akhir Debit', 'Akhir Kredit',
        ], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function incomeStatementRows(array $report): array
    {
        $rows = [];

        foreach (['revenue', 'cogs', 'expense'] as $section) {
            foreach ($this->list($this->array($report['sections'][$section] ?? []), 'lines') as $line) {
                $rows[] = [
                    ucfirst($section),
                    (string) ($line['code'] ?? ''),
                    (string) ($line['name'] ?? ''),
                    (string) ($line['amount'] ?? '0.00'),
                ];
            }
        }

        $rows[] = ['Laba Kotor', '', '', (string) ($report['gross_profit'] ?? '0.00')];
        $rows[] = ['Laba Bersih', '', '', (string) ($report['net_profit'] ?? '0.00')];

        return [['Bagian', 'Kode', 'Nama Akun', 'Nilai'], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function balanceSheetRows(array $report): array
    {
        $rows = [];

        foreach (['assets' => 'Aset', 'liabilities' => 'Kewajiban', 'equity' => 'Ekuitas'] as $key => $label) {
            foreach ($this->list($report, $key) as $line) {
                $rows[] = [
                    $label,
                    (string) ($line['code'] ?? ''),
                    (string) ($line['name'] ?? ''),
                    (string) ($line['amount'] ?? '0.00'),
                ];
            }
        }

        return [['Bagian', 'Kode', 'Nama Akun', 'Nilai'], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function cashBankRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'accounts') as $account) {
            $info = $this->array($account['account'] ?? []);

            foreach ($this->list($account, 'lines') as $line) {
                $rows[] = [
                    (string) ($info['code'] ?? ''),
                    (string) ($info['name'] ?? ''),
                    (string) ($line['journal_date'] ?? ''),
                    (string) ($line['journal_no'] ?? ''),
                    (string) ($line['description'] ?? ''),
                    (string) ($line['debit'] ?? '0.00'),
                    (string) ($line['credit'] ?? '0.00'),
                    (string) ($line['running_balance'] ?? '0.00'),
                ];
            }
        }

        return [[
            'Kode Akun', 'Nama Akun', 'Tanggal', 'No Jurnal',
            'Keterangan', 'Debit', 'Kredit', 'Saldo Berjalan',
        ], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function journalRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'journals') as $journal) {
            foreach ($this->list($journal, 'lines') as $line) {
                $rows[] = [
                    (string) ($journal['journal_no'] ?? ''),
                    (string) ($journal['journal_date'] ?? ''),
                    (string) ($journal['status'] ?? ''),
                    (string) ($journal['source_type'] ?? ''),
                    (string) ($line['account_code'] ?? ''),
                    (string) ($line['account_name'] ?? ''),
                    (string) ($line['debit'] ?? '0.00'),
                    (string) ($line['credit'] ?? '0.00'),
                ];
            }
        }

        return [[
            'No Jurnal', 'Tanggal', 'Status', 'Sumber',
            'Kode Akun', 'Nama Akun', 'Debit', 'Kredit',
        ], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function stockCardRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'warehouses') as $warehouse) {
            $info = $this->array($warehouse['warehouse'] ?? []);

            foreach ($this->list($warehouse, 'lines') as $line) {
                $rows[] = [
                    (string) ($info['code'] ?? ''),
                    (string) ($line['movement_date'] ?? ''),
                    (string) ($line['doc_type'] ?? ''),
                    (string) ($line['doc_id'] ?? ''),
                    (string) ($line['qty_in_base'] ?? '0.0000'),
                    (string) ($line['qty_out_base'] ?? '0.0000'),
                    (string) ($line['unit_cost'] ?? '0.0000'),
                    (string) ($line['running_qty'] ?? '0.0000'),
                    (string) ($line['running_value'] ?? '0.00'),
                ];
            }
        }

        return [[
            'Gudang', 'Tanggal', 'Dokumen', 'ID Dokumen',
            'Masuk', 'Keluar', 'Biaya', 'Saldo Qty', 'Saldo Nilai',
        ], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function stockPositionRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'items') as $item) {
            $info = $this->array($item['item'] ?? []);
            $warehouse = $this->array($item['warehouse'] ?? []);

            $rows[] = [
                (string) ($info['sku'] ?? ''),
                (string) ($info['name'] ?? ''),
                (string) ($warehouse['code'] ?? ''),
                (string) ($item['opening_qty'] ?? '0.0000'),
                (string) ($item['in_qty'] ?? '0.0000'),
                (string) ($item['out_qty'] ?? '0.0000'),
                (string) ($item['closing_qty'] ?? '0.0000'),
                (string) ($item['closing_value'] ?? '0.00'),
            ];
        }

        return [[
            'SKU', 'Nama Item', 'Gudang', 'Awal Qty',
            'Masuk', 'Keluar', 'Akhir Qty', 'Akhir Nilai',
        ], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function stockMovementRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'lines') as $line) {
            $item = $this->array($line['item'] ?? []);
            $warehouse = $this->array($line['warehouse'] ?? []);

            $rows[] = [
                (string) ($line['movement_date'] ?? ''),
                (string) ($item['sku'] ?? ''),
                (string) ($warehouse['code'] ?? ''),
                (string) ($line['doc_type'] ?? ''),
                (string) ($line['qty_in_base'] ?? '0.0000'),
                (string) ($line['qty_out_base'] ?? '0.0000'),
                (string) ($line['balance_qty'] ?? '0.0000'),
                (string) ($line['balance_value'] ?? '0.00'),
            ];
        }

        return [[
            'Tanggal', 'SKU', 'Gudang', 'Dokumen',
            'Masuk', 'Keluar', 'Saldo Qty', 'Saldo Nilai',
        ], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function agingRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'lines') as $line) {
            $partner = $this->array($line['customer'] ?? $line['supplier'] ?? []);

            $rows[] = [
                (string) ($line['doc_no'] ?? ''),
                (string) ($partner['code'] ?? ''),
                (string) ($partner['name'] ?? ''),
                (string) ($line['due_date'] ?? ''),
                (string) ($line['remaining'] ?? '0.00'),
                (string) ($line['days_overdue'] ?? '0'),
                (string) ($line['bucket'] ?? ''),
            ];
        }

        return [[
            'Dokumen', 'Kode Partner', 'Nama Partner',
            'Jatuh Tempo', 'Sisa', 'Hari Terlambat', 'Bucket',
        ], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function outstandingRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'lines') as $line) {
            $partner = $this->array($line['customer'] ?? $line['supplier'] ?? []);

            $rows[] = [
                (string) ($line['doc_no'] ?? ''),
                (string) ($line['doc_date'] ?? ''),
                (string) ($line['due_date'] ?? ''),
                (string) ($partner['code'] ?? ''),
                (string) ($partner['name'] ?? ''),
                (string) ($line['total'] ?? '0.00'),
                (string) ($line['paid_amount'] ?? '0.00'),
                (string) ($line['remaining'] ?? '0.00'),
            ];
        }

        return [[
            'Dokumen', 'Tanggal', 'Jatuh Tempo', 'Kode Partner',
            'Nama Partner', 'Total', 'Dibayar', 'Sisa',
        ], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function summaryRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'lines') as $line) {
            $partner = $this->array($line['customer'] ?? $line['supplier'] ?? []);

            $rows[] = [
                (string) ($partner['code'] ?? ''),
                (string) ($partner['name'] ?? ''),
                (string) ($line['invoice_count'] ?? '0'),
                (string) ($line['subtotal'] ?? '0.00'),
                (string) ($line['tax_amount'] ?? '0.00'),
                (string) ($line['total'] ?? '0.00'),
            ];
        }

        return [['Kode Partner', 'Nama Partner', 'Jumlah Invoice', 'Subtotal', 'PPN', 'Total'], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function orderOutstandingRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'lines') as $line) {
            $partner = $this->array($line['customer'] ?? $line['supplier'] ?? []);
            $item = $this->array($line['item'] ?? []);

            $rows[] = [
                (string) ($line['doc_no'] ?? ''),
                (string) ($line['doc_date'] ?? ''),
                (string) ($partner['code'] ?? ''),
                (string) ($partner['name'] ?? ''),
                (string) ($item['sku'] ?? ''),
                (string) ($line['remaining_qty_base'] ?? '0.0000'),
                (string) ($line['outstanding_value'] ?? '0.00'),
            ];
        }

        return [[
            'Dokumen', 'Tanggal', 'Kode Partner', 'Nama Partner',
            'SKU', 'Sisa Qty', 'Nilai Outstanding',
        ], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function reconciliationRows(array $report): array
    {
        $rows = [];

        foreach ($this->list($report, 'checks') as $check) {
            $rows[] = [
                (string) ($check['label'] ?? $check['check'] ?? ''),
                (string) ($check['expected'] ?? '0.00'),
                (string) ($check['actual'] ?? '0.00'),
                (string) ($check['difference'] ?? '0.00'),
                (string) ($check['status'] ?? ''),
            ];
        }

        return [['Pemeriksaan', 'Nilai GL', 'Nilai Sumber', 'Selisih', 'Status'], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function genericRows(array $report): array
    {
        $rows = [];

        foreach ($report as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $rows[] = [(string) $key, (string) $value];
            }
        }

        return [['Kunci', 'Nilai'], $rows];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{label: string, value: string}>
     */
    private function meta(array $report): array
    {
        $meta = [];

        foreach (['from' => 'Dari', 'to' => 'Sampai', 'as_of' => 'Per Tanggal'] as $key => $label) {
            if (isset($report[$key]) && is_scalar($report[$key])) {
                $meta[] = ['label' => $label, 'value' => (string) $report[$key]];
            }
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    private function list(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function filename(string $code, string $extension): string
    {
        return sprintf('%s_%s.%s', $code, now()->format('Ymd_His'), $extension);
    }
}
