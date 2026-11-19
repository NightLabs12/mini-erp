<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Master\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $code = 'CUS-'.strtoupper(Str::random(4));

        return [
            'code' => $code,
            'name' => 'Customer '.$code,
            'address' => fake()->address(),
            'phone' => fake()->numerify('08##########'),
            'email' => fake()->unique()->companyEmail(),
            'tax_id' => fake()->numerify('##.###.###.#-###.###'),
            'payment_term_days' => 30,
            'credit_limit' => '0.00',
        ];
    }
}
