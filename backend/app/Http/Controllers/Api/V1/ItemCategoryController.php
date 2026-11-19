<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Services\MasterUsageGuard;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\ItemCategoryRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ItemCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ItemCategoryController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly MasterUsageGuard $usageGuard) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', ItemCategory::class);

        $paginator = $this->paginateListQuery(ItemCategory::query(), $request, [
            'sortable' => ['code', 'name', 'item_type', 'created_at'],
            'filterable' => ['code', 'name', 'item_type', 'created_at'],
            'searchable' => ['code', 'name'],
            'default_sort' => 'code',
        ]);

        return new ApiCollection($paginator, ItemCategoryResource::class);
    }

    public function store(ItemCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', ItemCategory::class);

        $category = ItemCategory::query()->create($request->validated());

        return (new ItemCategoryResource($category->fresh() ?? $category))->response()->setStatusCode(201);
    }

    public function show(ItemCategory $itemCategory): ItemCategoryResource
    {
        $this->authorize('view', $itemCategory);

        return new ItemCategoryResource($itemCategory);
    }

    public function update(ItemCategoryRequest $request, ItemCategory $itemCategory): ItemCategoryResource
    {
        $this->authorize('update', $itemCategory);

        $itemCategory->update($request->validated());

        return new ItemCategoryResource($itemCategory->fresh() ?? $itemCategory);
    }

    public function audit(Request $request, ItemCategory $itemCategory): ApiCollection
    {
        $this->authorize('view', $itemCategory);

        return $this->auditTrailFor($itemCategory, $request);
    }

    public function destroy(ItemCategory $itemCategory): Response
    {
        $this->authorize('delete', $itemCategory);

        $this->usageGuard->assertUnused($itemCategory);

        $itemCategory->delete();

        return response()->noContent();
    }
}
