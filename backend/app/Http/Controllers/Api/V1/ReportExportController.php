<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Services\ReportExporter;
use App\Domain\Reporting\Services\ReportRegistry;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\ReportExportRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint generik laporan: GET /reports/{code}?format=json|xlsx|pdf.
 *
 * Satu pintu untuk JSON, Excel, dan PDF. Hak akses mengikuti permission
 * report.{code}.view yang sama dengan endpoint laporan eksplisit.
 */
final class ReportExportController extends Controller
{
    public function show(
        ReportExportRequest $request,
        string $report,
        ReportRegistry $registry,
        ReportExporter $exporter,
    ): JsonResponse|Response {
        if (! array_key_exists($report, ReportRegistry::TITLES)) {
            throw ApiException::notFound("Laporan '{$report}' tidak dikenal.", 'REPORT_NOT_FOUND');
        }

        $user = $request->user();

        if ($user === null || ! $user->can("report.{$report}.view")) {
            throw new ApiException(
                'Anda tidak memiliki izin untuk melihat laporan ini.',
                'FORBIDDEN',
                403,
            );
        }

        $data = $request->validated();
        $format = (string) ($data['format'] ?? 'json');
        $payload = $registry->build($report, $data);

        return match ($format) {
            'xlsx' => $exporter->toExcel($report, $payload),
            'pdf' => $exporter->toPdf($report, $payload),
            default => response()->json(['data' => $payload]),
        };
    }
}
