<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Accounting\Models\AccountingPeriod;
use Illuminate\Http\Request;

final class AccountingPeriodResource extends ApiResource
{
    private readonly AccountingPeriod $period;

    public function __construct(AccountingPeriod $period)
    {
        parent::__construct($period);

        $this->period = $period;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->period->getKey(),
            'year' => $this->period->year,
            'month' => $this->period->month,
            'label' => $this->period->label(),
            'status' => $this->period->status,
            'closed_by' => $this->period->closed_by,
            'closed_at' => $this->period->closed_at?->toIso8601String(),
            'reopened_by' => $this->period->reopened_by,
            'reopened_at' => $this->period->reopened_at?->toIso8601String(),
            ...$this->timestamps(),
        ];
    }
}
