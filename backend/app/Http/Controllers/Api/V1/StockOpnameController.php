<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Services\StockOpnameService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockOpnameRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\StockOpnameResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class StockOpnameController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly StockOpnameService $opnames) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', StockOpname::class);

        $paginator = $this->paginateListQuery(
            StockOpname::query()->with([
                'warehouse',
                'items.item',
                'items.unit',
            ]),
            $request,
            [
                'sortable' => ['doc_no', 'doc_date', 'status', 'created_at'],
                'filterable' => ['doc_no', 'doc_date', 'status', 'warehouse_id', 'created_at'],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, StockOpnameResource::class);
    }

    public function store(StockOpnameRequest $request): JsonResponse
    {
        $this->authorize('create', StockOpname::class);

        $opname = $this->opnames->create($request->validated());

        return (new StockOpnameResource($opname))->response()->setStatusCode(201);
    }

    public function show(StockOpname $stockOpname): StockOpnameResource
    {
        $this->authorize('view', $stockOpname);

        return new StockOpnameResource($this->load($stockOpname));
    }

    public function update(
        StockOpnameRequest $request,
        StockOpname $stockOpname,
    ): StockOpnameResource {
        $this->authorize('update', $stockOpname);

        return new StockOpnameResource(
            $this->opnames->update($stockOpname, $request->validated()),
        );
    }

    public function destroy(StockOpname $stockOpname): Response
    {
        $this->authorize('delete', $stockOpname);

        $this->opnames->deleteDraft($stockOpname);

        return response()->noContent();
    }

    public function approve(StockOpname $stockOpname): StockOpnameResource
    {
        $this->authorize('approve', $stockOpname);

        return new StockOpnameResource($this->opnames->approve($stockOpname));
    }

    public function disapprove(StockOpname $stockOpname): StockOpnameResource
    {
        $this->authorize('disapprove', $stockOpname);

        return new StockOpnameResource($this->opnames->disapprove($stockOpname));
    }

    public function audit(Request $request, StockOpname $stockOpname): ApiCollection
    {
        $this->authorize('view', $stockOpname);

        return $this->auditTrailFor($stockOpname, $request);
    }

    private function load(StockOpname $opname): StockOpname
    {
        return $opname->refresh()->load([
            'warehouse',
            'items.item',
            'items.unit',
            'journal',
        ]);
    }
}
