<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Master\Models\Unit;
use App\Domain\Master\Services\MasterUsageGuard;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\UnitRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\UnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class UnitController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly MasterUsageGuard $usageGuard) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', Unit::class);

        $paginator = $this->paginateListQuery(Unit::query(), $request, [
            'sortable' => ['code', 'name', 'created_at'],
            'filterable' => ['code', 'name', 'created_at'],
            'searchable' => ['code', 'name'],
            'default_sort' => 'code',
        ]);

        return new ApiCollection($paginator, UnitResource::class);
    }

    public function store(UnitRequest $request): JsonResponse
    {
        $this->authorize('create', Unit::class);

        $unit = Unit::query()->create($request->validated());

        return (new UnitResource($unit->fresh() ?? $unit))->response()->setStatusCode(201);
    }

    public function show(Unit $unit): UnitResource
    {
        $this->authorize('view', $unit);

        return new UnitResource($unit);
    }

    public function update(UnitRequest $request, Unit $unit): UnitResource
    {
        $this->authorize('update', $unit);

        $unit->update($request->validated());

        return new UnitResource($unit->fresh() ?? $unit);
    }

    public function audit(Request $request, Unit $unit): ApiCollection
    {
        $this->authorize('view', $unit);

        return $this->auditTrailFor($unit, $request);
    }

    public function destroy(Unit $unit): Response
    {
        $this->authorize('delete', $unit);

        $this->usageGuard->assertUnused($unit);

        $unit->delete();

        return response()->noContent();
    }
}
