<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Core\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Request;

final class AuditLogController extends Controller
{
    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $paginator = $this->paginateListQuery(AuditLog::query()->with('user'), $request, [
            'sortable' => ['id', 'event', 'created_at'],
            'filterable' => ['auditable_type', 'auditable_id', 'user_id', 'event', 'created_at'],
            'default_sort' => '-id',
        ]);

        return new ApiCollection($paginator, AuditLogResource::class);
    }
}
