<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Purchasing\Models\SupplierPaymentAllocation;
use Illuminate\Http\Request;

final class SupplierPaymentAllocationResource extends ApiResource
{
    private readonly SupplierPaymentAllocation $allocation;

    public function __construct(SupplierPaymentAllocation $allocation)
    {
        parent::__construct($allocation);

        $this->allocation = $allocation;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->allocation->getKey(),
            'purchase_invoice_id' => $this->allocation->purchase_invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn (): ?array => $this->allocation->invoice === null ? null : [
                'id' => $this->allocation->invoice->getKey(),
                'doc_no' => $this->allocation->invoice->doc_no,
                'total' => $this->allocation->invoice->total,
                'paid_amount' => $this->allocation->invoice->paid_amount,
                'due_date' => $this->allocation->invoice->due_date?->toDateString(),
            ]),
            'amount' => $this->allocation->amount,
            ...$this->timestamps(),
        ];
    }
}
