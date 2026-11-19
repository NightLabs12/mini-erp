<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Master\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $code = 'SUP-'.strtoupper(Str::random(4));

        return [
            'code' => $code,
            'name' => 'Supplier '.$code,
            'address' => fake()->address(),
            'phone' => fake()->numerify('08##########'),
            'email' => fake()->unique()->companyEmail(),
            'tax_id' => fake()->numerify('##.###.###.#-###.###'),
            'payment_term_days' => 30,
        ];
    }
}
