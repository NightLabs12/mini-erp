<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Core\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Mencatat request mutasi ke api_request_logs.
 *
 * Memakai terminate() agar berjalan setelah respons dikirim, sehingga
 * pengguna tidak menunggu penulisan log dan $request->user() sudah terisi
 * oleh middleware auth di level route.
 */
final class LogApiRequest
{
    private const LOGGED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! in_array($request->method(), self::LOGGED_METHODS, true) || ! $request->is('api/*')) {
            return;
        }

        $startedAt = $request->attributes->get('started_at');
        $duration = is_float($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : 0;

        try {
            ApiRequestLog::query()->create([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'method' => $request->method(),
                'path' => mb_substr($request->path(), 0, 255),
                'status' => $response->getStatusCode(),
                'duration_ms' => max(0, $duration),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent() === null ? null : mb_substr($request->userAgent(), 0, 512),
            ]);
        } catch (Throwable) {
            // Logging request tidak boleh menggagalkan permintaan pengguna.
        }
    }
}
