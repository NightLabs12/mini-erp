<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Sales\Models\DeliveryOrder;
use App\Domain\Sales\Models\DeliveryOrderItem;
use Illuminate\Http\Request;

final class DeliveryOrderResource extends ApiResource
{
    private readonly DeliveryOrder $delivery;

    public function __construct(DeliveryOrder $delivery)
    {
        parent::__construct($delivery);

        $this->delivery = $delivery;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->delivery->getKey(),
            'doc_no' => $this->delivery->doc_no,
            'doc_date' => $this->delivery->doc_date?->toDateString(),
            'period_id' => $this->delivery->period_id,
            'sales_order_id' => $this->delivery->sales_order_id,
            'sales_order' => $this->whenLoaded('salesOrder', fn (): ?array => $this->delivery->salesOrder === null ? null : [
                'id' => $this->delivery->salesOrder->getKey(),
                'doc_no' => $this->delivery->salesOrder->doc_no,
            ]),
            'customer_id' => $this->delivery->customer_id,
            'customer' => $this->whenLoaded('customer', fn (): ?array => $this->delivery->customer === null ? null : [
                'id' => $this->delivery->customer->getKey(),
                'code' => $this->delivery->customer->code,
                'name' => $this->delivery->customer->name,
                'address' => $this->delivery->customer->address,
            ]),
            'warehouse_id' => $this->delivery->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->delivery->warehouse === null ? null : [
                'id' => $this->delivery->warehouse->getKey(),
                'code' => $this->delivery->warehouse->code,
                'name' => $this->delivery->warehouse->name,
            ]),
            'status' => $this->delivery->status,
            'notes' => $this->delivery->notes,
            'journal_id' => $this->delivery->journal_id,
            'journal' => $this->whenLoaded(
                'journal',
                fn (): ?array => $this->delivery->journal === null
                    ? null
                    : (new JournalResource($this->delivery->journal))->resolve(),
            ),
            'created_by' => $this->delivery->created_by,
            'approved_by' => $this->delivery->approved_by,
            'approved_at' => $this->delivery->approved_at?->toIso8601String(),
            'items' => $this->whenLoaded(
                'items',
                fn (): array => $this->delivery->items
                    ->sortBy([['line_no', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(static fn (DeliveryOrderItem $item): array => (new DeliveryOrderItemResource($item))->resolve())
                    ->all(),
            ),
            ...$this->timestamps(),
        ];
    }
}
