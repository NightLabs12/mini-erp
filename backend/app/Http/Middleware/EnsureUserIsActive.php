<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak token milik user yang dinonaktifkan.
 *
 * User yang di-soft-delete sudah ditolak Sanctum saat resolusi token (401),
 * sedangkan user nonaktif masih bisa diresolusi sehingga perlu pemeriksaan
 * eksplisit di sini.
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'isActive') && ! $user->isActive()) {
            throw new ApiException(
                'Akun Anda dinonaktifkan. Hubungi administrator.',
                'USER_INACTIVE',
                403,
            );
        }

        return $next($request);
    }
}
