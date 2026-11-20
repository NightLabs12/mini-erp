<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Sales\Models\SalesOrderItem;
use Illuminate\Http\Request;

final class SalesOrderItemResource extends ApiResource
{
    private readonly SalesOrderItem $item;

    public function __construct(SalesOrderItem $item)
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
            'unit_price' => $this->item->unit_price,
            'discount_pct' => $this->item->discount_pct,
            'unit_price_base' => $this->item->unit_price_base,
            'line_total' => $this->item->line_total,
            'delivered_qty_base' => $this->item->delivered_qty_base,
            'invoiced_qty_base' => $this->item->invoiced_qty_base,
            ...$this->timestamps(),
        ];
    }
}
