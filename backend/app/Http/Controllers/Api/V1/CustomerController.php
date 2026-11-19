<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Master\Models\Customer;
use App\Domain\Master\Services\MasterUsageGuard;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\CustomerRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CustomerController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly MasterUsageGuard $usageGuard) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', Customer::class);

        $paginator = $this->paginateListQuery(Customer::query(), $request, [
            'sortable' => ['code', 'name', 'credit_limit', 'created_at'],
            'filterable' => ['code', 'name', 'payment_term_days', 'created_at'],
            'searchable' => ['code', 'name', 'email', 'phone'],
            'default_sort' => 'code',
        ]);

        return new ApiCollection($paginator, CustomerResource::class);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = Customer::query()->create($request->validated());

        return (new CustomerResource($customer->fresh() ?? $customer))->response()->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        $this->authorize('view', $customer);

        return new CustomerResource($customer);
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        $this->authorize('update', $customer);

        $customer->update($request->validated());

        return new CustomerResource($customer->fresh() ?? $customer);
    }

    public function audit(Request $request, Customer $customer): ApiCollection
    {
        $this->authorize('view', $customer);

        return $this->auditTrailFor($customer, $request);
    }

    public function destroy(Customer $customer): Response
    {
        $this->authorize('delete', $customer);

        $this->usageGuard->assertUnused($customer);

        $customer->delete();

        return response()->noContent();
    }
}
