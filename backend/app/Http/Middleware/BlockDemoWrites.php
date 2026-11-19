<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mode demo: menolak perubahan pada data kritis.
 *
 * Yang dijaga adalah data yang bisa merusak demo untuk pengunjung lain:
 * user, role, dan setting. Transaksi bisnis (PO, GR, invoice) tetap boleh
 * dibuat agar pengunjung bisa mencoba alur lengkap.
 *
 * Perintah `demo:reset` berjalan lewat artisan, bukan HTTP, sehingga tidak
 * terpengaruh middleware ini.
 */
final class BlockDemoWrites
{
    /** @var list<string> */
    private const PROTECTED_PATHS = [
        'api/v1/users*',
        'api/v1/roles*',
        'api/v1/settings*',
        'api/v1/account-settings*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.demo_mode')) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if (! $request->is(...self::PROTECTED_PATHS)) {
            return $next($request);
        }

        throw new ApiException(
            'Mode demo: data pengguna, role, dan setting tidak dapat diubah.',
            'DEMO_MODE_READONLY',
            403,
        );
    }
}
