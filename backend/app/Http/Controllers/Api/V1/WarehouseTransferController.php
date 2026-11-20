<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\WarehouseTransfer;
use App\Domain\Inventory\Services\WarehouseTransferService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\WarehouseTransferRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\WarehouseTransferResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WarehouseTransferController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly WarehouseTransferService $transfers) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', WarehouseTransfer::class);

        $paginator = $this->paginateListQuery(
            WarehouseTransfer::query()->with([
                'fromWarehouse',
                'toWarehouse',
                'items.item',
                'items.unit',
            ]),
            $request,
            [
                'sortable' => ['doc_no', 'doc_date', 'status', 'created_at'],
                'filterable' => [
                    'doc_no',
                    'doc_date',
                    'status',
                    'from_warehouse_id',
                    'to_warehouse_id',
                    'created_at',
                ],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, WarehouseTransferResource::class);
    }

    public function store(WarehouseTransferRequest $request): JsonResponse
    {
        $this->authorize('create', WarehouseTransfer::class);

        $transfer = $this->transfers->create($request->validated());

        return (new WarehouseTransferResource($transfer))->response()->setStatusCode(201);
    }

    public function show(WarehouseTransfer $warehouseTransfer): WarehouseTransferResource
    {
        $this->authorize('view', $warehouseTransfer);

        return new WarehouseTransferResource($this->load($warehouseTransfer));
    }

    public function update(
        WarehouseTransferRequest $request,
        WarehouseTransfer $warehouseTransfer,
    ): WarehouseTransferResource {
        $this->authorize('update', $warehouseTransfer);

        return new WarehouseTransferResource(
            $this->transfers->update($warehouseTransfer, $request->validated()),
        );
    }

    public function destroy(WarehouseTransfer $warehouseTransfer): Response
    {
        $this->authorize('delete', $warehouseTransfer);

        $this->transfers->deleteDraft($warehouseTransfer);

        return response()->noContent();
    }

    public function approve(WarehouseTransfer $warehouseTransfer): WarehouseTransferResource
    {
        $this->authorize('approve', $warehouseTransfer);

        return new WarehouseTransferResource($this->transfers->approve($warehouseTransfer));
    }

    public function disapprove(WarehouseTransfer $warehouseTransfer): WarehouseTransferResource
    {
        $this->authorize('disapprove', $warehouseTransfer);

        return new WarehouseTransferResource($this->transfers->disapprove($warehouseTransfer));
    }

    public function audit(Request $request, WarehouseTransfer $warehouseTransfer): ApiCollection
    {
        $this->authorize('view', $warehouseTransfer);

        return $this->auditTrailFor($warehouseTransfer, $request);
    }

    private function load(WarehouseTransfer $transfer): WarehouseTransfer
    {
        return $transfer->refresh()->load([
            'fromWarehouse',
            'toWarehouse',
            'items.item',
            'items.unit',
        ]);
    }
}
