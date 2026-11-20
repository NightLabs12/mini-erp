<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\WarehouseTransfer;
use App\Domain\Inventory\Models\WarehouseTransferItem;
use Illuminate\Http\Request;

final class WarehouseTransferResource extends ApiResource
{
    private readonly WarehouseTransfer $transfer;

    public function __construct(WarehouseTransfer $transfer)
    {
        parent::__construct($transfer);

        $this->transfer = $transfer;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->transfer->getKey(),
            'doc_no' => $this->transfer->doc_no,
            'doc_date' => $this->transfer->doc_date?->toDateString(),
            'period_id' => $this->transfer->period_id,
            'from_warehouse_id' => $this->transfer->from_warehouse_id,
            'from_warehouse' => $this->whenLoaded('fromWarehouse', fn (): ?array => $this->transfer->fromWarehouse === null ? null : [
                'id' => $this->transfer->fromWarehouse->getKey(),
                'code' => $this->transfer->fromWarehouse->code,
                'name' => $this->transfer->fromWarehouse->name,
            ]),
            'to_warehouse_id' => $this->transfer->to_warehouse_id,
            'to_warehouse' => $this->whenLoaded('toWarehouse', fn (): ?array => $this->transfer->toWarehouse === null ? null : [
                'id' => $this->transfer->toWarehouse->getKey(),
                'code' => $this->transfer->toWarehouse->code,
                'name' => $this->transfer->toWarehouse->name,
            ]),
            'status' => $this->transfer->status,
            'notes' => $this->transfer->notes,
            'created_by' => $this->transfer->created_by,
            'approved_by' => $this->transfer->approved_by,
            'approved_at' => $this->transfer->approved_at?->toIso8601String(),
            'items' => $this->whenLoaded(
                'items',
                fn (): array => $this->transfer->items
                    ->sortBy([['line_no', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(static fn (WarehouseTransferItem $item): array => (new WarehouseTransferItemResource($item))->resolve())
                    ->all(),
            ),
            ...$this->timestamps(),
        ];
    }
}
