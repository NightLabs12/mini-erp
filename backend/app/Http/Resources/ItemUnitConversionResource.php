<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Models\ItemUnitConversion;
use Illuminate\Http\Request;

final class ItemUnitConversionResource extends ApiResource
{
    private readonly ItemUnitConversion $conversion;

    public function __construct(ItemUnitConversion $conversion)
    {
        parent::__construct($conversion);

        $this->conversion = $conversion;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->conversion->getKey(),
            'item_id' => $this->conversion->item_id,
            'unit_id' => $this->conversion->unit_id,
            'unit' => $this->whenLoaded('unit', fn (): ?array => $this->conversion->unit === null ? null : [
                'id' => $this->conversion->unit->getKey(),
                'code' => $this->conversion->unit->code,
                'name' => $this->conversion->unit->name,
            ]),
            'factor_to_base' => $this->conversion->factor_to_base,
            'is_purchase_default' => (bool) $this->conversion->is_purchase_default,
            'is_sales_default' => (bool) $this->conversion->is_sales_default,
            ...$this->timestamps(),
        ];
    }
}
