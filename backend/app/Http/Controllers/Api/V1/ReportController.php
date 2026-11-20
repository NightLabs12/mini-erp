<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Services\GeneralLedgerService;
use App\Domain\Accounting\Services\TrialBalanceService;
use App\Domain\Inventory\Services\StockReportService;
use App\Domain\Purchasing\Services\PurchaseReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FinancialReportRequest;
use App\Http\Requests\Inventory\StockCardRequest;
use App\Http\Requests\Inventory\StockReportRequest;
use App\Http\Requests\Purchasing\ApReportRequest;
use App\Http\Requests\Purchasing\PurchaseReportRequest;
use Illuminate\Http\JsonResponse;

/**
 * Laporan keuangan dan persediaan. Hak akses dijaga middleware permission
 * per kode laporan di route.
 */
final class ReportController extends Controller
{
    public function generalLedger(
        FinancialReportRequest $request,
        GeneralLedgerService $ledger,
    ): JsonResponse {
        $data = $request->validated();

        $report = $ledger->report(
            isset($data['account_id']) ? (int) $data['account_id'] : null,
            (string) $data['from'],
            (string) $data['to'],
        );

        return response()->json(['data' => $report]);
    }

    public function trialBalance(
        FinancialReportRequest $request,
        TrialBalanceService $trialBalance,
    ): JsonResponse {
        $data = $request->validated();

        $report = $trialBalance->report((string) $data['from'], (string) $data['to']);

        return response()->json(['data' => $report]);
    }

    public function stockCard(
        StockCardRequest $request,
        StockReportService $reports,
    ): JsonResponse {
        $data = $request->validated();

        $report = $reports->stockCard(
            (int) $data['item_id'],
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            (string) $data['from'],
            (string) $data['to'],
        );

        return response()->json(['data' => $report]);
    }

    public function stockPosition(
        StockReportRequest $request,
        StockReportService $reports,
    ): JsonResponse {
        $data = $request->validated();

        $report = $reports->stockPosition(
            isset($data['item_id']) ? (int) $data['item_id'] : null,
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            (string) $data['from'],
            (string) $data['to'],
        );

        return response()->json(['data' => $report]);
    }

    public function stockMovement(
        StockReportRequest $request,
        StockReportService $reports,
    ): JsonResponse {
        $data = $request->validated();

        $report = $reports->stockMovement(
            isset($data['item_id']) ? (int) $data['item_id'] : null,
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            (string) $data['from'],
            (string) $data['to'],
        );

        return response()->json(['data' => $report]);
    }

    public function poOutstanding(
        PurchaseReportRequest $request,
        PurchaseReportService $reports,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json(['data' => $reports->poOutstanding(
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            (string) $data['from'],
            (string) $data['to'],
        )]);
    }

    public function purchaseSummary(
        PurchaseReportRequest $request,
        PurchaseReportService $reports,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json(['data' => $reports->purchaseSummary(
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            (string) $data['from'],
            (string) $data['to'],
        )]);
    }

    public function apOutstanding(
        ApReportRequest $request,
        PurchaseReportService $reports,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json(['data' => $reports->apOutstanding(
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            (string) $data['as_of'],
        )]);
    }

    public function apAging(
        ApReportRequest $request,
        PurchaseReportService $reports,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json(['data' => $reports->apAging(
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            (string) $data['as_of'],
        )]);
    }
}
