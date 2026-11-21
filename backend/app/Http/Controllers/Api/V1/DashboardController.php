<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Services\DashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class DashboardController extends Controller
{
    public function summary(DashboardService $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->summary()]);
    }
}
