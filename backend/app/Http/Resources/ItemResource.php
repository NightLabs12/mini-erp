<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Models\Item;
use Illuminate\Http\Request;

final class ItemResource extends ApiResource
{
    private readonly Item $item;

    public function __construct(Item $item)
    {
        parent::__construct($item);

        $this->item = $item;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->item->getKey(),
            'sku' => $this->item->sku,
            'name' => $this->item->name,
            'category_id' => $this->item->category_id,
            'category' => $this->whenLoaded('category', fn (): ?array => $this->item->category === null ? null : [
                'id' => $this->item->category->getKey(),
                'code' => $this->item->category->code,
                'name' => $this->item->category->name,
                'item_type' => $this->item->category->item_type,
            ]),
            'base_unit_id' => $this->item->base_unit_id,
            'base_unit' => $this->whenLoaded('baseUnit', fn (): ?array => $this->item->baseUnit === null ? null : [
                'id' => $this->item->baseUnit->getKey(),
                'code' => $this->item->baseUnit->code,
                'name' => $this->item->baseUnit->name,
            ]),
            'min_stock' => $this->item->min_stock,
            'is_active' => (bool) $this->item->is_active,
            'conversions' => $this->whenLoaded(
                'conversions',
                fn (): array => ItemUnitConversionResource::collection($this->item->conversions)->resolve($request),
            ),
            ...$this->timestamps(),
        ];
    }
}
