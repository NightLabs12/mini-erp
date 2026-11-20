<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\PurchaseInvoiceItem;
use Illuminate\Http\Request;

final class PurchaseInvoiceResource extends ApiResource
{
    private readonly PurchaseInvoice $invoice;

    public function __construct(PurchaseInvoice $invoice)
    {
        parent::__construct($invoice);

        $this->invoice = $invoice;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->invoice->getKey(),
            'doc_no' => $this->invoice->doc_no,
            'doc_date' => $this->invoice->doc_date?->toDateString(),
            'period_id' => $this->invoice->period_id,
            'supplier_id' => $this->invoice->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn (): ?array => $this->invoice->supplier === null ? null : [
                'id' => $this->invoice->supplier->getKey(),
                'code' => $this->invoice->supplier->code,
                'name' => $this->invoice->supplier->name,
                'payment_term_days' => $this->invoice->supplier->payment_term_days,
            ]),
            'due_date' => $this->invoice->due_date?->toDateString(),
            'status' => $this->invoice->status,
            'subtotal' => $this->invoice->subtotal,
            'tax_rate' => $this->invoice->tax_rate,
            'tax_amount' => $this->invoice->tax_amount,
            'total' => $this->invoice->total,
            'paid_amount' => $this->invoice->paid_amount,
            'remaining' => $this->invoice->remaining(),
            'notes' => $this->invoice->notes,
            'journal_id' => $this->invoice->journal_id,
            'journal' => $this->whenLoaded(
                'journal',
                fn (): ?array => $this->invoice->journal === null
                    ? null
                    : (new JournalResource($this->invoice->journal))->resolve(),
            ),
            'created_by' => $this->invoice->created_by,
            'approved_by' => $this->invoice->approved_by,
            'approved_at' => $this->invoice->approved_at?->toIso8601String(),
            'items' => $this->whenLoaded(
                'items',
                fn (): array => $this->invoice->items
                    ->sortBy([['line_no', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(static fn (PurchaseInvoiceItem $item): array => (new PurchaseInvoiceItemResource($item))->resolve())
                    ->all(),
            ),
            ...$this->timestamps(),
        ];
    }
}
