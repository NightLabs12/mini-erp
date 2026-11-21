<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Services\BalanceSheetService;
use App\Domain\Accounting\Services\CashBankReportService;
use App\Domain\Accounting\Services\GeneralLedgerService;
use App\Domain\Accounting\Services\IncomeStatementService;
use App\Domain\Accounting\Services\JournalReportService;
use App\Domain\Accounting\Services\ReconciliationService;
use App\Domain\Accounting\Services\TrialBalanceService;
use App\Domain\Inventory\Services\StockReportService;
use App\Domain\Purchasing\Services\PurchaseReportService;
use App\Domain\Sales\Services\SalesReportService;
use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;

/**
 * Sumber tunggal pembentukan data laporan berdasarkan kode laporan.
 *
 * Dipakai endpoint generik /reports/{code} sehingga ekspor Excel/PDF dan
 * JSON memakai builder yang sama. Setiap kode memetakan parameter query ke
 * service domain yang sesuai.
 */
final class ReportRegistry
{
    /** @var array<string, string> */
    public const TITLES = [
        'general-ledger' => 'Buku Besar',
        'trial-balance' => 'Neraca Saldo',
        'income-statement' => 'Laba Rugi',
        'balance-sheet' => 'Neraca',
        'cash-bank' => 'Laporan Kas dan Bank',
        'journal' => 'Laporan Jurnal',
        'stock-card' => 'Kartu Stok',
        'stock-position' => 'Posisi Stok',
        'stock-movement' => 'Mutasi Stok',
        'ap-aging' => 'AP Aging',
        'ar-aging' => 'AR Aging',
        'ap-outstanding' => 'AP Outstanding',
        'ar-outstanding' => 'AR Outstanding',
        'sales-summary' => 'Ringkasan Penjualan',
        'purchase-summary' => 'Ringkasan Pembelian',
        'so-outstanding' => 'SO Outstanding',
        'po-outstanding' => 'PO Outstanding',
        'reconciliation' => 'Rekonsiliasi',
    ];

    public function __construct(
        private readonly GeneralLedgerService $generalLedger,
        private readonly TrialBalanceService $trialBalance,
        private readonly IncomeStatementService $incomeStatement,
        private readonly BalanceSheetService $balanceSheet,
        private readonly CashBankReportService $cashBank,
        private readonly JournalReportService $journalReport,
        private readonly StockReportService $stock,
        private readonly PurchaseReportService $purchase,
        private readonly SalesReportService $sales,
        private readonly ReconciliationService $reconciliation,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function build(string $code, array $params = []): array
    {
        $from = $this->date($params['from'] ?? null, now()->startOfMonth());
        $to = $this->date($params['to'] ?? null, now()->endOfMonth());
        $asOf = $this->date($params['as_of'] ?? null, now());

        return match ($code) {
            'general-ledger' => $this->generalLedger->report(
                $this->intOrNull($params['account_id'] ?? null),
                $from,
                $to,
            ),
            'trial-balance' => $this->trialBalance->report($from, $to),
            'income-statement' => $this->incomeStatement->report(
                $from,
                $to,
                $this->dateOrNull($params['compare_from'] ?? null),
                $this->dateOrNull($params['compare_to'] ?? null),
            ),
            'balance-sheet' => $this->balanceSheet->report(
                $asOf,
                $this->dateOrNull($params['compare_as_of'] ?? null),
            ),
            'cash-bank' => $this->cashBank->report($from, $to),
            'journal' => $this->journalReport->report(
                $from,
                $to,
                $this->stringOrNull($params['source_type'] ?? null),
                $this->stringOrNull($params['status'] ?? null),
            ),
            'stock-card' => $this->stock->stockCard(
                $this->requiredInt($params['item_id'] ?? null, 'item_id'),
                $this->intOrNull($params['warehouse_id'] ?? null),
                $from,
                $to,
            ),
            'stock-position' => $this->stock->stockPosition(
                $this->intOrNull($params['item_id'] ?? null),
                $this->intOrNull($params['warehouse_id'] ?? null),
                $from,
                $to,
            ),
            'stock-movement' => $this->stock->stockMovement(
                $this->intOrNull($params['item_id'] ?? null),
                $this->intOrNull($params['warehouse_id'] ?? null),
                $from,
                $to,
            ),
            'ap-aging' => $this->purchase->apAging($this->intOrNull($params['supplier_id'] ?? null), $asOf),
            'ap-outstanding' => $this->purchase->apOutstanding(
                $this->intOrNull($params['supplier_id'] ?? null),
                $asOf,
            ),
            'ar-aging' => $this->sales->arAging($this->intOrNull($params['customer_id'] ?? null), $asOf),
            'ar-outstanding' => $this->sales->arOutstanding(
                $this->intOrNull($params['customer_id'] ?? null),
                $asOf,
            ),
            'sales-summary' => $this->sales->salesSummary(
                $this->intOrNull($params['customer_id'] ?? null),
                $from,
                $to,
            ),
            'purchase-summary' => $this->purchase->purchaseSummary(
                $this->intOrNull($params['supplier_id'] ?? null),
                $from,
                $to,
            ),
            'so-outstanding' => $this->sales->soOutstanding(
                $this->intOrNull($params['customer_id'] ?? null),
                $from,
                $to,
            ),
            'po-outstanding' => $this->purchase->poOutstanding(
                $this->intOrNull($params['supplier_id'] ?? null),
                $from,
                $to,
            ),
            'reconciliation' => $this->reconciliation->report($this->reconciliationDate($params, $asOf)),
            default => throw ApiException::notFound(
                "Laporan '{$code}' tidak dikenal.",
                'REPORT_NOT_FOUND',
            ),
        };
    }

    public function title(string $code): string
    {
        return self::TITLES[$code] ?? $code;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function reconciliationDate(array $params, string $fallback): string
    {
        $year = $this->intOrNull($params['year'] ?? null);
        $month = $this->intOrNull($params['month'] ?? null);

        if ($year !== null && $month !== null) {
            return CarbonImmutable::create($year, $month, 1)->endOfMonth()->toDateString();
        }

        return $fallback;
    }

    private function date(mixed $value, mixed $default): string
    {
        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->toDateString();
        }

        return CarbonImmutable::parse($default)->toDateString();
    }

    private function dateOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value)->toDateString()
            : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function requiredInt(mixed $value, string $field): int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            throw ApiException::unprocessable(
                "Parameter {$field} wajib diisi untuk laporan ini.",
                'REPORT_PARAMETER_REQUIRED',
                [$field => ["{$field} wajib diisi."]],
            );
        }

        return (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
