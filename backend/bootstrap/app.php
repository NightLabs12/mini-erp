<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Middleware\BlockDemoWrites;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogApiRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi('api');

        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->api(append: [
            LogApiRequest::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'demo.readonly' => BlockDemoWrites::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([
            ApiException::class,
        ]);

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $render = static fn (string $message, string $code, int $status, array $errors = []): JsonResponse => response()->json([
                'message' => $message,
                'code' => $code,
                'errors' => (object) $errors,
            ], $status);

            if ($e instanceof ApiException) {
                return $render($e->getMessage(), $e->errorCode(), $e->status(), $e->errors());
            }

            if ($e instanceof ValidationException) {
                return $render('Data yang dikirim tidak valid.', 'VALIDATION_ERROR', 422, $e->errors());
            }

            if ($e instanceof AuthenticationException) {
                return $render('Sesi tidak valid atau token kedaluwarsa.', 'UNAUTHENTICATED', 401);
            }

            if ($e instanceof AccessDeniedHttpException) {
                return $render('Anda tidak memiliki izin untuk aksi ini.', 'FORBIDDEN', 403);
            }

            if ($e instanceof ModelNotFoundException) {
                return $render('Data tidak ditemukan.', 'NOT_FOUND', 404);
            }

            if ($e instanceof NotFoundHttpException) {
                return $render('Endpoint tidak ditemukan.', 'NOT_FOUND', 404);
            }

            if ($e instanceof MethodNotAllowedHttpException) {
                return $render('Metode HTTP tidak diizinkan untuk endpoint ini.', 'METHOD_NOT_ALLOWED', 405);
            }

            if ($e instanceof ThrottleRequestsException) {
                return $render('Terlalu banyak permintaan. Coba lagi nanti.', 'RATE_LIMITED', 429)
                    ->withHeaders($e->getHeaders());
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    400 => 'BAD_REQUEST',
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    405 => 'METHOD_NOT_ALLOWED',
                    409 => 'CONFLICT',
                    422 => 'UNPROCESSABLE_ENTITY',
                    429 => 'RATE_LIMITED',
                    default => 'HTTP_ERROR',
                };

                return $render($e->getMessage() ?: 'Permintaan tidak dapat diproses.', $code, $status);
            }

            return $render('Terjadi kesalahan pada server.', 'SERVER_ERROR', 500);
        });
    })->create();
