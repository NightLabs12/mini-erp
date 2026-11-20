<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\StockBalance;
use Illuminate\Http\Request;

final class StockBalanceResource extends ApiResource
{
    private readonly StockBalance $balance;

    public function __construct(StockBalance $balance)
    {
        parent::__construct($balance);

        $this->balance = $balance;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->balance->getKey(),
            'item_id' => $this->balance->item_id,
            'item' => $this->whenLoaded('item', fn (): ?array => $this->balance->item === null ? null : [
                'id' => $this->balance->item->getKey(),
                'sku' => $this->balance->item->sku,
                'name' => $this->balance->item->name,
                'base_unit_id' => $this->balance->item->base_unit_id,
            ]),
            'warehouse_id' => $this->balance->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->balance->warehouse === null ? null : [
                'id' => $this->balance->warehouse->getKey(),
                'code' => $this->balance->warehouse->code,
                'name' => $this->balance->warehouse->name,
            ]),
            'qty_base' => $this->balance->qty_base,
            'avg_cost' => $this->balance->avg_cost,
            'value' => $this->balance->value,
            ...$this->timestamps(),
        ];
    }
}
