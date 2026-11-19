<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Master\Models\Warehouse;
use App\Domain\Master\Services\MasterUsageGuard;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\WarehouseRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\WarehouseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WarehouseController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly MasterUsageGuard $usageGuard) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', Warehouse::class);

        $paginator = $this->paginateListQuery(Warehouse::query(), $request, [
            'sortable' => ['code', 'name', 'is_active', 'created_at'],
            'filterable' => ['code', 'name', 'is_active', 'created_at'],
            'searchable' => ['code', 'name', 'address'],
            'default_sort' => 'code',
        ]);

        return new ApiCollection($paginator, WarehouseResource::class);
    }

    public function store(WarehouseRequest $request): JsonResponse
    {
        $this->authorize('create', Warehouse::class);

        $warehouse = Warehouse::query()->create($request->validated());

        return (new WarehouseResource($warehouse->fresh() ?? $warehouse))->response()->setStatusCode(201);
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        $this->authorize('view', $warehouse);

        return new WarehouseResource($warehouse);
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        $this->authorize('update', $warehouse);

        $warehouse->update($request->validated());

        return new WarehouseResource($warehouse->fresh() ?? $warehouse);
    }

    public function audit(Request $request, Warehouse $warehouse): ApiCollection
    {
        $this->authorize('view', $warehouse);

        return $this->auditTrailFor($warehouse, $request);
    }

    public function destroy(Warehouse $warehouse): Response
    {
        $this->authorize('delete', $warehouse);

        $this->usageGuard->assertUnused($warehouse);

        $warehouse->delete();

        return response()->noContent();
    }
}
