<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Purchasing\Models\SupplierPaymentAllocation;
use Illuminate\Http\Request;

final class SupplierPaymentResource extends ApiResource
{
    private readonly SupplierPayment $payment;

    public function __construct(SupplierPayment $payment)
    {
        parent::__construct($payment);

        $this->payment = $payment;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->payment->getKey(),
            'doc_no' => $this->payment->doc_no,
            'doc_date' => $this->payment->doc_date?->toDateString(),
            'period_id' => $this->payment->period_id,
            'supplier_id' => $this->payment->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn (): ?array => $this->payment->supplier === null ? null : [
                'id' => $this->payment->supplier->getKey(),
                'code' => $this->payment->supplier->code,
                'name' => $this->payment->supplier->name,
            ]),
            'method' => $this->payment->method,
            'account_id' => $this->payment->account_id,
            'account' => $this->whenLoaded('account', fn (): ?array => $this->payment->account === null ? null : [
                'id' => $this->payment->account->getKey(),
                'code' => $this->payment->account->code,
                'name' => $this->payment->account->name,
            ]),
            'amount' => $this->payment->amount,
            'status' => $this->payment->status,
            'notes' => $this->payment->notes,
            'journal_id' => $this->payment->journal_id,
            'journal' => $this->whenLoaded(
                'journal',
                fn (): ?array => $this->payment->journal === null
                    ? null
                    : (new JournalResource($this->payment->journal))->resolve(),
            ),
            'created_by' => $this->payment->created_by,
            'approved_by' => $this->payment->approved_by,
            'approved_at' => $this->payment->approved_at?->toIso8601String(),
            'allocations' => $this->whenLoaded(
                'allocations',
                fn (): array => $this->payment->allocations
                    ->sortBy('purchase_invoice_id')
                    ->values()
                    ->map(static fn (SupplierPaymentAllocation $allocation): array => (new SupplierPaymentAllocationResource($allocation))->resolve())
                    ->all(),
            ),
            ...$this->timestamps(),
        ];
    }
}
