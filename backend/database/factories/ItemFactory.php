<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(Str::random(8)),
            'name' => fake()->words(2, true),
            'category_id' => ItemCategory::factory(),
            'base_unit_id' => Unit::factory(),
            'min_stock' => '0.0000',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
