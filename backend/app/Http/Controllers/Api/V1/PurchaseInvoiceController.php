<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Services\PurchaseInvoiceService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseInvoiceRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\PurchaseInvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PurchaseInvoiceController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly PurchaseInvoiceService $invoices) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', PurchaseInvoice::class);

        $paginator = $this->paginateListQuery(
            PurchaseInvoice::query()->with(['supplier', 'items.item', 'items.unit']),
            $request,
            [
                'sortable' => ['doc_no', 'doc_date', 'due_date', 'status', 'total', 'paid_amount', 'created_at'],
                'filterable' => [
                    'doc_no',
                    'doc_date',
                    'due_date',
                    'status',
                    'supplier_id',
                    'created_at',
                ],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, PurchaseInvoiceResource::class);
    }

    public function store(PurchaseInvoiceRequest $request): JsonResponse
    {
        $this->authorize('create', PurchaseInvoice::class);

        $invoice = $this->invoices->create($request->validated());

        return (new PurchaseInvoiceResource($invoice))->response()->setStatusCode(201);
    }

    public function show(PurchaseInvoice $purchaseInvoice): PurchaseInvoiceResource
    {
        $this->authorize('view', $purchaseInvoice);

        return new PurchaseInvoiceResource($this->load($purchaseInvoice));
    }

    public function update(PurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): PurchaseInvoiceResource
    {
        $this->authorize('update', $purchaseInvoice);

        return new PurchaseInvoiceResource(
            $this->invoices->update($purchaseInvoice, $request->validated()),
        );
    }

    public function destroy(PurchaseInvoice $purchaseInvoice): Response
    {
        $this->authorize('delete', $purchaseInvoice);

        $this->invoices->deleteDraft($purchaseInvoice);

        return response()->noContent();
    }

    public function approve(PurchaseInvoice $purchaseInvoice): PurchaseInvoiceResource
    {
        $this->authorize('approve', $purchaseInvoice);

        return new PurchaseInvoiceResource($this->invoices->approve($purchaseInvoice));
    }

    public function disapprove(PurchaseInvoice $purchaseInvoice): PurchaseInvoiceResource
    {
        $this->authorize('disapprove', $purchaseInvoice);

        return new PurchaseInvoiceResource($this->invoices->disapprove($purchaseInvoice));
    }

    public function audit(Request $request, PurchaseInvoice $purchaseInvoice): ApiCollection
    {
        $this->authorize('view', $purchaseInvoice);

        return $this->auditTrailFor($purchaseInvoice, $request);
    }

    private function load(PurchaseInvoice $invoice): PurchaseInvoice
    {
        return $invoice->refresh()->load([
            'supplier',
            'journal',
            'items.item',
            'items.unit',
            'items.goodsReceiptItem',
        ]);
    }
}
