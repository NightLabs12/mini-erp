<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Models\SellingPrice;
use Illuminate\Http\Request;

final class SellingPriceResource extends ApiResource
{
    private readonly SellingPrice $sellingPrice;

    public function __construct(SellingPrice $sellingPrice, private readonly ?string $unitPriceBase = null)
    {
        parent::__construct($sellingPrice);

        $this->sellingPrice = $sellingPrice;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->sellingPrice->getKey(),
            'item_id' => $this->sellingPrice->item_id,
            'item' => $this->whenLoaded('item', fn (): ?array => $this->sellingPrice->item === null ? null : [
                'id' => $this->sellingPrice->item->getKey(),
                'sku' => $this->sellingPrice->item->sku,
                'name' => $this->sellingPrice->item->name,
            ]),
            'unit_id' => $this->sellingPrice->unit_id,
            'unit' => $this->whenLoaded('unit', fn (): ?array => $this->sellingPrice->unit === null ? null : [
                'id' => $this->sellingPrice->unit->getKey(),
                'code' => $this->sellingPrice->unit->code,
                'name' => $this->sellingPrice->unit->name,
            ]),
            'customer_id' => $this->sellingPrice->customer_id,
            'customer' => $this->whenLoaded('customer', fn (): ?array => $this->sellingPrice->customer === null ? null : [
                'id' => $this->sellingPrice->customer->getKey(),
                'code' => $this->sellingPrice->customer->code,
                'name' => $this->sellingPrice->customer->name,
            ]),
            'price' => $this->sellingPrice->price,
            'unit_price_base' => $this->unitPriceBase,
            'valid_from' => $this->sellingPrice->valid_from?->format('Y-m-d'),
            'valid_to' => $this->sellingPrice->valid_to?->format('Y-m-d'),
            ...$this->timestamps(),
        ];
    }
}
