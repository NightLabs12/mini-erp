<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Master\Models\Supplier;
use App\Domain\Master\Services\MasterUsageGuard;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SupplierRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\SupplierResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SupplierController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly MasterUsageGuard $usageGuard) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', Supplier::class);

        $paginator = $this->paginateListQuery(Supplier::query(), $request, [
            'sortable' => ['code', 'name', 'created_at'],
            'filterable' => ['code', 'name', 'payment_term_days', 'created_at'],
            'searchable' => ['code', 'name', 'email', 'phone'],
            'default_sort' => 'code',
        ]);

        return new ApiCollection($paginator, SupplierResource::class);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = Supplier::query()->create($request->validated());

        return (new SupplierResource($supplier->fresh() ?? $supplier))->response()->setStatusCode(201);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        $this->authorize('view', $supplier);

        return new SupplierResource($supplier);
    }

    public function update(SupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $this->authorize('update', $supplier);

        $supplier->update($request->validated());

        return new SupplierResource($supplier->fresh() ?? $supplier);
    }

    public function audit(Request $request, Supplier $supplier): ApiCollection
    {
        $this->authorize('view', $supplier);

        return $this->auditTrailFor($supplier, $request);
    }

    public function destroy(Supplier $supplier): Response
    {
        $this->authorize('delete', $supplier);

        $this->usageGuard->assertUnused($supplier);

        $supplier->delete();

        return response()->noContent();
    }
}
