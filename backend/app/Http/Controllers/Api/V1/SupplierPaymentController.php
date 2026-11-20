<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Purchasing\Services\SupplierPaymentService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierPaymentRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\SupplierPaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SupplierPaymentController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly SupplierPaymentService $payments) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', SupplierPayment::class);

        $paginator = $this->paginateListQuery(
            SupplierPayment::query()->with(['supplier', 'account', 'allocations.invoice']),
            $request,
            [
                'sortable' => ['doc_no', 'doc_date', 'status', 'amount', 'created_at'],
                'filterable' => ['doc_no', 'doc_date', 'status', 'supplier_id', 'method', 'created_at'],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, SupplierPaymentResource::class);
    }

    public function store(SupplierPaymentRequest $request): JsonResponse
    {
        $this->authorize('create', SupplierPayment::class);

        $payment = $this->payments->create($request->validated());

        return (new SupplierPaymentResource($payment))->response()->setStatusCode(201);
    }

    public function show(SupplierPayment $supplierPayment): SupplierPaymentResource
    {
        $this->authorize('view', $supplierPayment);

        return new SupplierPaymentResource($this->load($supplierPayment));
    }

    public function update(SupplierPaymentRequest $request, SupplierPayment $supplierPayment): SupplierPaymentResource
    {
        $this->authorize('update', $supplierPayment);

        return new SupplierPaymentResource(
            $this->payments->update($supplierPayment, $request->validated()),
        );
    }

    public function destroy(SupplierPayment $supplierPayment): Response
    {
        $this->authorize('delete', $supplierPayment);

        $this->payments->deleteDraft($supplierPayment);

        return response()->noContent();
    }

    public function approve(SupplierPayment $supplierPayment): SupplierPaymentResource
    {
        $this->authorize('approve', $supplierPayment);

        return new SupplierPaymentResource($this->payments->approve($supplierPayment));
    }

    public function disapprove(SupplierPayment $supplierPayment): SupplierPaymentResource
    {
        $this->authorize('disapprove', $supplierPayment);

        return new SupplierPaymentResource($this->payments->disapprove($supplierPayment));
    }

    public function audit(Request $request, SupplierPayment $supplierPayment): ApiCollection
    {
        $this->authorize('view', $supplierPayment);

        return $this->auditTrailFor($supplierPayment, $request);
    }

    private function load(SupplierPayment $payment): SupplierPayment
    {
        return $payment->refresh()->load([
            'supplier',
            'account',
            'journal',
            'allocations.invoice',
        ]);
    }
}
