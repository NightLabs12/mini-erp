<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Services\GeneralLedgerService;
use App\Domain\Accounting\Services\TrialBalanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FinancialReportRequest;
use Illuminate\Http\JsonResponse;

/**
 * Laporan keuangan awal. Hak akses dijaga middleware permission di route
 * (report.general-ledger.view / report.trial-balance.view).
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
}
