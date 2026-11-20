<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesOrderItem;
use Illuminate\Http\Request;

final class SalesOrderResource extends ApiResource
{
    private readonly SalesOrder $order;

    public function __construct(SalesOrder $order)
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
            'customer_id' => $this->order->customer_id,
            'customer' => $this->whenLoaded('customer', fn (): ?array => $this->order->customer === null ? null : [
                'id' => $this->order->customer->getKey(),
                'code' => $this->order->customer->code,
                'name' => $this->order->customer->name,
                'payment_term_days' => $this->order->customer->payment_term_days,
                'credit_limit' => $this->order->customer->credit_limit,
            ]),
            'warehouse_id' => $this->order->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->order->warehouse === null ? null : [
                'id' => $this->order->warehouse->getKey(),
                'code' => $this->order->warehouse->code,
                'name' => $this->order->warehouse->name,
            ]),
            'status' => $this->order->status,
            'subtotal' => $this->order->subtotal,
            'discount_amount' => $this->order->discount_amount,
            'tax_rate' => $this->order->tax_rate,
            'tax_amount' => $this->order->tax_amount,
            'total' => $this->order->total,
            'closed_manually' => (bool) $this->order->closed_manually,
            'credit_warning' => $this->order->creditWarning,
            'notes' => $this->order->notes,
            'created_by' => $this->order->created_by,
            'approved_by' => $this->order->approved_by,
            'approved_at' => $this->order->approved_at?->toIso8601String(),
            'items' => $this->whenLoaded(
                'items',
                fn (): array => $this->order->items
                    ->sortBy([['line_no', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(static fn (SalesOrderItem $item): array => (new SalesOrderItemResource($item))->resolve())
                    ->all(),
            ),
            ...$this->timestamps(),
        ];
    }
}
