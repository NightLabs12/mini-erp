<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = 'ok';

        try {
            DB::select('select 1');
        } catch (Throwable) {
            $database = 'down';
        }

        $healthy = $database === 'ok';

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'version' => config('app.version'),
            'environment' => app()->environment(),
            'time' => now()->toIso8601String(),
            'checks' => [
                'database' => $database,
            ],
        ], $healthy ? 200 : 503);
    }
}
