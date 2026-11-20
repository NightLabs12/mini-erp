<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Services\SalesOrderService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesOrderRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\SalesOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SalesOrderController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly SalesOrderService $orders) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', SalesOrder::class);

        $paginator = $this->paginateListQuery(
            SalesOrder::query()->with(['customer', 'warehouse', 'items.item', 'items.unit']),
            $request,
            [
                'sortable' => ['doc_no', 'doc_date', 'status', 'total', 'created_at'],
                'filterable' => ['doc_no', 'doc_date', 'status', 'customer_id', 'warehouse_id', 'created_at'],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, SalesOrderResource::class);
    }

    public function store(SalesOrderRequest $request): JsonResponse
    {
        $this->authorize('create', SalesOrder::class);

        $order = $this->orders->create($request->validated());

        return (new SalesOrderResource($order))->response()->setStatusCode(201);
    }

    public function show(SalesOrder $salesOrder): SalesOrderResource
    {
        $this->authorize('view', $salesOrder);

        return new SalesOrderResource($this->load($salesOrder));
    }

    public function update(SalesOrderRequest $request, SalesOrder $salesOrder): SalesOrderResource
    {
        $this->authorize('update', $salesOrder);

        return new SalesOrderResource($this->orders->update($salesOrder, $request->validated()));
    }

    public function destroy(SalesOrder $salesOrder): Response
    {
        $this->authorize('delete', $salesOrder);

        $this->orders->deleteDraft($salesOrder);

        return response()->noContent();
    }

    public function approve(SalesOrder $salesOrder): SalesOrderResource
    {
        $this->authorize('approve', $salesOrder);

        return new SalesOrderResource($this->orders->approve($salesOrder));
    }

    public function disapprove(SalesOrder $salesOrder): SalesOrderResource
    {
        $this->authorize('disapprove', $salesOrder);

        return new SalesOrderResource($this->orders->disapprove($salesOrder));
    }

    public function close(SalesOrder $salesOrder): SalesOrderResource
    {
        $this->authorize('close', $salesOrder);

        return new SalesOrderResource($this->orders->close($salesOrder));
    }

    public function audit(Request $request, SalesOrder $salesOrder): ApiCollection
    {
        $this->authorize('view', $salesOrder);

        return $this->auditTrailFor($salesOrder, $request);
    }

    private function load(SalesOrder $order): SalesOrder
    {
        return $order->refresh()->load(['customer', 'warehouse', 'items.item', 'items.unit']);
    }
}
