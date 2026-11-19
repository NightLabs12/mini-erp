<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Core\Models\AuditLog;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\AuditLogResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Endpoint audit per resource: GET /{resource}/{id}/audit.
 *
 * Dipakai ulang oleh setiap controller dokumen pada fase berikutnya.
 */
trait InteractsWithAuditTrail
{
    protected function auditTrailFor(Model $model, Request $request): ApiCollection
    {
        $perPage = (int) $request->query('per_page', '25');
        $perPage = max(1, min($perPage, 100));

        $paginator = AuditLog::query()
            ->with('user')
            ->where('auditable_type', $model->getMorphClass())
            ->where('auditable_id', $model->getKey())
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return new ApiCollection($paginator, AuditLogResource::class);
    }
}
