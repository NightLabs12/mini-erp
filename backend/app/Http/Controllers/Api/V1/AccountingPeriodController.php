<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\PeriodClosingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountingPeriodResource;
use App\Http\Resources\ApiCollection;
use Illuminate\Http\Request;

final class AccountingPeriodController extends Controller
{
    public function __construct(private readonly PeriodClosingService $periods) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', AccountingPeriod::class);

        $paginator = $this->paginateListQuery(AccountingPeriod::query(), $request, [
            'sortable' => ['year', 'month', 'status', 'closed_at', 'created_at'],
            'filterable' => ['year', 'month', 'status'],
            'default_sort' => '-year',
            'per_page' => 24,
        ]);

        return new ApiCollection($paginator, AccountingPeriodResource::class);
    }

    public function show(AccountingPeriod $accountingPeriod): AccountingPeriodResource
    {
        $this->authorize('view', $accountingPeriod);

        return new AccountingPeriodResource($accountingPeriod);
    }

    public function close(AccountingPeriod $accountingPeriod): AccountingPeriodResource
    {
        $this->authorize('close', $accountingPeriod);

        return new AccountingPeriodResource($this->periods->close($accountingPeriod));
    }

    public function reopen(AccountingPeriod $accountingPeriod): AccountingPeriodResource
    {
        $this->authorize('reopen', $accountingPeriod);

        return new AccountingPeriodResource($this->periods->reopen($accountingPeriod));
    }
}
