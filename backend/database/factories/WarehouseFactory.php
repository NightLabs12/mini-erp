<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Master\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $code = strtoupper(Str::random(4));

        return [
            'code' => $code,
            'name' => 'Gudang '.$code,
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }
}
