<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $code = strtoupper(Str::random(4));

        return [
            'code' => $code,
            'name' => 'Satuan '.$code,
        ];
    }
}
