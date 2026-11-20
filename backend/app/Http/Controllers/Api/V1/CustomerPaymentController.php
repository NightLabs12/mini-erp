<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sales\Models\CustomerPayment;
use App\Domain\Sales\Services\CustomerPaymentService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CustomerPaymentRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\CustomerPaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CustomerPaymentController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly CustomerPaymentService $payments) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', CustomerPayment::class);

        $paginator = $this->paginateListQuery(
            CustomerPayment::query()->with(['customer', 'account', 'allocations.invoice']),
            $request,
            [
                'sortable' => ['doc_no', 'doc_date', 'status', 'amount', 'created_at'],
                'filterable' => ['doc_no', 'doc_date', 'status', 'customer_id', 'method', 'created_at'],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, CustomerPaymentResource::class);
    }

    public function store(CustomerPaymentRequest $request): JsonResponse
    {
        $this->authorize('create', CustomerPayment::class);

        $payment = $this->payments->create($request->validated());

        return (new CustomerPaymentResource($payment))->response()->setStatusCode(201);
    }

    public function show(CustomerPayment $customerPayment): CustomerPaymentResource
    {
        $this->authorize('view', $customerPayment);

        return new CustomerPaymentResource($this->load($customerPayment));
    }

    public function update(CustomerPaymentRequest $request, CustomerPayment $customerPayment): CustomerPaymentResource
    {
        $this->authorize('update', $customerPayment);

        return new CustomerPaymentResource($this->payments->update($customerPayment, $request->validated()));
    }

    public function destroy(CustomerPayment $customerPayment): Response
    {
        $this->authorize('delete', $customerPayment);

        $this->payments->deleteDraft($customerPayment);

        return response()->noContent();
    }

    public function approve(CustomerPayment $customerPayment): CustomerPaymentResource
    {
        $this->authorize('approve', $customerPayment);

        return new CustomerPaymentResource($this->payments->approve($customerPayment));
    }

    public function disapprove(CustomerPayment $customerPayment): CustomerPaymentResource
    {
        $this->authorize('disapprove', $customerPayment);

        return new CustomerPaymentResource($this->payments->disapprove($customerPayment));
    }

    public function audit(Request $request, CustomerPayment $customerPayment): ApiCollection
    {
        $this->authorize('view', $customerPayment);

        return $this->auditTrailFor($customerPayment, $request);
    }

    private function load(CustomerPayment $payment): CustomerPayment
    {
        return $payment->refresh()->load([
            'customer',
            'account',
            'journal',
            'allocations.invoice',
        ]);
    }
}
