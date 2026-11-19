<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Master\Models\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ItemCategory>
 */
class ItemCategoryFactory extends Factory
{
    protected $model = ItemCategory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $code = strtoupper(Str::random(4));

        return [
            'code' => $code,
            'name' => 'Kategori '.$code,
            'item_type' => 'trading',
        ];
    }

    public function rawMaterial(): static
    {
        return $this->state(fn (): array => ['item_type' => 'raw_material']);
    }

    public function finishedGood(): static
    {
        return $this->state(fn (): array => ['item_type' => 'finished_good']);
    }
}
