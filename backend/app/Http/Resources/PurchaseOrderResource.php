<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Http\Request;

final class PurchaseOrderResource extends ApiResource
{
    private readonly PurchaseOrder $order;

    public function __construct(PurchaseOrder $order)
    {
        parent::__construct($order);

        $this->order = $order;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->order->getKey(),
            'doc_no' => $this->order->doc_no,
            'doc_date' => $this->order->doc_date?->toDateString(),
            'period_id' => $this->order->period_id,
            'purchase_request_id' => $this->order->purchase_request_id,
            'supplier_id' => $this->order->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn (): ?array => $this->order->supplier === null ? null : [
                'id' => $this->order->supplier->getKey(),
                'code' => $this->order->supplier->code,
                'name' => $this->order->supplier->name,
                'payment_term_days' => $this->order->supplier->payment_term_days,
            ]),
            'warehouse_id' => $this->order->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->order->warehouse === null ? null : [
                'id' => $this->order->warehouse->getKey(),
                'code' => $this->order->warehouse->code,
                'name' => $this->order->warehouse->name,
            ]),
            'expected_date' => $this->order->expected_date?->toDateString(),
            'status' => $this->order->status,
            'subtotal' => $this->order->subtotal,
            'discount_amount' => $this->order->discount_amount,
            'tax_rate' => $this->order->tax_rate,
            'tax_amount' => $this->order->tax_amount,
            'total' => $this->order->total,
            'closed_manually' => (bool) $this->order->closed_manually,
            'notes' => $this->order->notes,
            'created_by' => $this->order->created_by,
            'approved_by' => $this->order->approved_by,
            'approved_at' => $this->order->approved_at?->toIso8601String(),
            'items' => $this->whenLoaded(
                'items',
                fn (): array => $this->order->items
                    ->sortBy([['line_no', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(static fn (PurchaseOrderItem $item): array => (new PurchaseOrderItemResource($item))->resolve())
                    ->all(),
            ),
            ...$this->timestamps(),
        ];
    }
}
