<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SellingPrice>
 */
class SellingPriceFactory extends Factory
{
    protected $model = SellingPrice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'unit_id' => Unit::factory(),
            'customer_id' => null,
            'price' => '15000.0000',
            'valid_from' => null,
            'valid_to' => null,
        ];
    }
}
