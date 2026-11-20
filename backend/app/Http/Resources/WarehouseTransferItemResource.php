<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\WarehouseTransferItem;
use Illuminate\Http\Request;

final class WarehouseTransferItemResource extends ApiResource
{
    private readonly WarehouseTransferItem $item;

    public function __construct(WarehouseTransferItem $item)
    {
        parent::__construct($item);

        $this->item = $item;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->item->getKey(),
            'item_id' => $this->item->item_id,
            'item' => $this->whenLoaded('item', fn (): ?array => $this->item->item === null ? null : [
                'id' => $this->item->item->getKey(),
                'sku' => $this->item->item->sku,
                'name' => $this->item->item->name,
                'base_unit_id' => $this->item->item->base_unit_id,
            ]),
            'unit_id' => $this->item->unit_id,
            'unit' => $this->whenLoaded('unit', fn (): ?array => $this->item->unit === null ? null : [
                'id' => $this->item->unit->getKey(),
                'code' => $this->item->unit->code,
                'name' => $this->item->unit->name,
            ]),
            'line_no' => $this->item->line_no,
            'qty' => $this->item->qty,
            'qty_base' => $this->item->qty_base,
            'unit_cost_base' => $this->item->unit_cost_base,
            'total_value' => $this->item->total_value,
            ...$this->timestamps(),
        ];
    }
}
