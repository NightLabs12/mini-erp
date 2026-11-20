<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Purchasing\Models\PurchaseRequest;
use App\Domain\Purchasing\Services\PurchaseRequestService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ConvertPurchaseRequestRequest;
use App\Http\Requests\Purchasing\PurchaseRequestRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\PurchaseOrderResource;
use App\Http\Resources\PurchaseRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PurchaseRequestController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly PurchaseRequestService $requests) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $paginator = $this->paginateListQuery(
            PurchaseRequest::query()->with(['supplier', 'warehouse', 'items.item', 'items.unit']),
            $request,
            [
                'sortable' => ['doc_no', 'doc_date', 'status', 'created_at'],
                'filterable' => ['doc_no', 'doc_date', 'status', 'supplier_id', 'warehouse_id', 'created_at'],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, PurchaseRequestResource::class);
    }

    public function store(PurchaseRequestRequest $request): JsonResponse
    {
        $this->authorize('create', PurchaseRequest::class);

        $created = $this->requests->create($request->validated());

        return (new PurchaseRequestResource($created))->response()->setStatusCode(201);
    }

    public function show(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $this->authorize('view', $purchaseRequest);

        return new PurchaseRequestResource($this->load($purchaseRequest));
    }

    public function update(PurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $this->authorize('update', $purchaseRequest);

        return new PurchaseRequestResource(
            $this->requests->update($purchaseRequest, $request->validated()),
        );
    }

    public function destroy(PurchaseRequest $purchaseRequest): Response
    {
        $this->authorize('delete', $purchaseRequest);

        $this->requests->deleteDraft($purchaseRequest);

        return response()->noContent();
    }

    public function approve(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $this->authorize('approve', $purchaseRequest);

        return new PurchaseRequestResource($this->requests->approve($purchaseRequest));
    }

    public function disapprove(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $this->authorize('disapprove', $purchaseRequest);

        return new PurchaseRequestResource($this->requests->disapprove($purchaseRequest));
    }

    public function close(PurchaseRequest $purchaseRequest): PurchaseRequestResource
    {
        $this->authorize('close', $purchaseRequest);

        return new PurchaseRequestResource($this->requests->close($purchaseRequest));
    }

    public function convert(
        ConvertPurchaseRequestRequest $request,
        PurchaseRequest $purchaseRequest,
    ): JsonResponse {
        $this->authorize('create', PurchaseRequest::class);

        $order = $this->requests->convertToOrder($purchaseRequest, $request->validated());

        return (new PurchaseOrderResource($order))->response()->setStatusCode(201);
    }

    public function audit(Request $request, PurchaseRequest $purchaseRequest): ApiCollection
    {
        $this->authorize('view', $purchaseRequest);

        return $this->auditTrailFor($purchaseRequest, $request);
    }

    private function load(PurchaseRequest $request): PurchaseRequest
    {
        return $request->refresh()->load(['supplier', 'warehouse', 'items.item', 'items.unit']);
    }
}
