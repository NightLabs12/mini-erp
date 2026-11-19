<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\ItemUnitConversion;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemUnitConversion>
 */
class ItemUnitConversionFactory extends Factory
{
    protected $model = ItemUnitConversion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'unit_id' => Unit::factory(),
            'factor_to_base' => '12.0000',
            'is_purchase_default' => false,
            'is_sales_default' => false,
        ];
    }
}
