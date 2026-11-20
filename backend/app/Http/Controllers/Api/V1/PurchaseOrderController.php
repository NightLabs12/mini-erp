<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Core\Services\SettingService;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseOrderRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\PurchaseOrderResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class PurchaseOrderController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly SettingService $settings,
    ) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $paginator = $this->paginateListQuery(
            PurchaseOrder::query()->with(['supplier', 'warehouse', 'items.item', 'items.unit']),
            $request,
            [
                'sortable' => ['doc_no', 'doc_date', 'status', 'total', 'created_at'],
                'filterable' => [
                    'doc_no',
                    'doc_date',
                    'status',
                    'supplier_id',
                    'warehouse_id',
                    'purchase_request_id',
                    'created_at',
                ],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, PurchaseOrderResource::class);
    }

    public function store(PurchaseOrderRequest $request): JsonResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $order = $this->orders->create($request->validated());

        return (new PurchaseOrderResource($order))->response()->setStatusCode(201);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($this->load($purchaseOrder));
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('update', $purchaseOrder);

        return new PurchaseOrderResource(
            $this->orders->update($purchaseOrder, $request->validated()),
        );
    }

    public function destroy(PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('delete', $purchaseOrder);

        $this->orders->deleteDraft($purchaseOrder);

        return response()->noContent();
    }

    public function approve(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('approve', $purchaseOrder);

        return new PurchaseOrderResource($this->orders->approve($purchaseOrder));
    }

    public function disapprove(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('disapprove', $purchaseOrder);

        return new PurchaseOrderResource($this->orders->disapprove($purchaseOrder));
    }

    public function close(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('close', $purchaseOrder);

        return new PurchaseOrderResource($this->orders->close($purchaseOrder));
    }

    public function print(PurchaseOrder $purchaseOrder): SymfonyResponse
    {
        $this->authorize('print', $purchaseOrder);

        $order = $this->load($purchaseOrder);

        $pdf = Pdf::loadView('purchasing.purchase-order', [
            'order' => $order,
            'company' => $this->settings->get('company_name', 'PT Contoh Niaga Makmur'),
        ])->setPaper('a4');

        return $pdf->download(str_replace('/', '-', $order->doc_no).'.pdf');
    }

    public function audit(Request $request, PurchaseOrder $purchaseOrder): ApiCollection
    {
        $this->authorize('view', $purchaseOrder);

        return $this->auditTrailFor($purchaseOrder, $request);
    }

    private function load(PurchaseOrder $order): PurchaseOrder
    {
        return $order->refresh()->load([
            'supplier',
            'warehouse',
            'purchaseRequest',
            'items.item',
            'items.unit',
        ]);
    }
}
