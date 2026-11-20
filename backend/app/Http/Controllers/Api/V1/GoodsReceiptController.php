<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Services\GoodsReceiptService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\GoodsReceiptRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\GoodsReceiptResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class GoodsReceiptController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', GoodsReceipt::class);

        $paginator = $this->paginateListQuery(
            GoodsReceipt::query()->with([
                'purchaseOrder',
                'supplier',
                'warehouse',
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
                    'purchase_order_id',
                    'supplier_id',
                    'warehouse_id',
                    'created_at',
                ],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, GoodsReceiptResource::class);
    }

    public function store(GoodsReceiptRequest $request): JsonResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        $receipt = $this->receipts->create($request->validated());

        return (new GoodsReceiptResource($receipt))->response()->setStatusCode(201);
    }

    public function show(GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        $this->authorize('view', $goodsReceipt);

        return new GoodsReceiptResource($this->load($goodsReceipt));
    }

    public function update(GoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        $this->authorize('update', $goodsReceipt);

        return new GoodsReceiptResource(
            $this->receipts->update($goodsReceipt, $request->validated()),
        );
    }

    public function destroy(GoodsReceipt $goodsReceipt): Response
    {
        $this->authorize('delete', $goodsReceipt);

        $this->receipts->deleteDraft($goodsReceipt);

        return response()->noContent();
    }

    public function approve(GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        $this->authorize('approve', $goodsReceipt);

        return new GoodsReceiptResource($this->receipts->approve($goodsReceipt));
    }

    public function disapprove(GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        $this->authorize('disapprove', $goodsReceipt);

        return new GoodsReceiptResource($this->receipts->disapprove($goodsReceipt));
    }

    public function audit(Request $request, GoodsReceipt $goodsReceipt): ApiCollection
    {
        $this->authorize('view', $goodsReceipt);

        return $this->auditTrailFor($goodsReceipt, $request);
    }

    private function load(GoodsReceipt $receipt): GoodsReceipt
    {
        return $receipt->refresh()->load([
            'purchaseOrder',
            'supplier',
            'warehouse',
            'journal',
            'items.item',
            'items.unit',
            'items.purchaseOrderItem',
        ]);
    }
}
