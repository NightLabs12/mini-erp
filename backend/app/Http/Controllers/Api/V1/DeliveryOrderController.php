<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Core\Services\SettingService;
use App\Domain\Sales\Models\DeliveryOrder;
use App\Domain\Sales\Services\DeliveryOrderService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\DeliveryOrderRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\DeliveryOrderResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class DeliveryOrderController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(
        private readonly DeliveryOrderService $deliveries,
        private readonly SettingService $settings,
    ) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', DeliveryOrder::class);

        $paginator = $this->paginateListQuery(
            DeliveryOrder::query()->with(['salesOrder', 'customer', 'warehouse', 'items.item', 'items.unit']),
            $request,
            [
                'sortable' => ['doc_no', 'doc_date', 'status', 'created_at'],
                'filterable' => ['doc_no', 'doc_date', 'status', 'sales_order_id', 'customer_id', 'warehouse_id', 'created_at'],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, DeliveryOrderResource::class);
    }

    public function store(DeliveryOrderRequest $request): JsonResponse
    {
        $this->authorize('create', DeliveryOrder::class);

        $delivery = $this->deliveries->create($request->validated());

        return (new DeliveryOrderResource($delivery))->response()->setStatusCode(201);
    }

    public function show(DeliveryOrder $deliveryOrder): DeliveryOrderResource
    {
        $this->authorize('view', $deliveryOrder);

        return new DeliveryOrderResource($this->load($deliveryOrder));
    }

    public function update(DeliveryOrderRequest $request, DeliveryOrder $deliveryOrder): DeliveryOrderResource
    {
        $this->authorize('update', $deliveryOrder);

        return new DeliveryOrderResource($this->deliveries->update($deliveryOrder, $request->validated()));
    }

    public function destroy(DeliveryOrder $deliveryOrder): Response
    {
        $this->authorize('delete', $deliveryOrder);

        $this->deliveries->deleteDraft($deliveryOrder);

        return response()->noContent();
    }

    public function approve(DeliveryOrder $deliveryOrder): DeliveryOrderResource
    {
        $this->authorize('approve', $deliveryOrder);

        return new DeliveryOrderResource($this->deliveries->approve($deliveryOrder));
    }

    public function disapprove(DeliveryOrder $deliveryOrder): DeliveryOrderResource
    {
        $this->authorize('disapprove', $deliveryOrder);

        return new DeliveryOrderResource($this->deliveries->disapprove($deliveryOrder));
    }

    public function print(DeliveryOrder $deliveryOrder): SymfonyResponse
    {
        $this->authorize('print', $deliveryOrder);

        $delivery = $this->load($deliveryOrder);

        $pdf = Pdf::loadView('sales.delivery-order', [
            'delivery' => $delivery,
            'company' => $this->settings->get('company_name', 'PT Contoh Niaga Makmur'),
        ])->setPaper('a4');

        return $pdf->download(str_replace('/', '-', $delivery->doc_no).'.pdf');
    }

    public function audit(Request $request, DeliveryOrder $deliveryOrder): ApiCollection
    {
        $this->authorize('view', $deliveryOrder);

        return $this->auditTrailFor($deliveryOrder, $request);
    }

    private function load(DeliveryOrder $delivery): DeliveryOrder
    {
        return $delivery->refresh()->load([
            'salesOrder',
            'customer',
            'warehouse',
            'journal',
            'items.item',
            'items.unit',
            'items.salesOrderItem',
        ]);
    }
}
