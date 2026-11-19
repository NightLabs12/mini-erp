<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Models\Unit;
use Illuminate\Http\Request;

final class UnitResource extends ApiResource
{
    private readonly Unit $unit;

    public function __construct(Unit $unit)
    {
        parent::__construct($unit);

        $this->unit = $unit;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->unit->getKey(),
            'code' => $this->unit->code,
            'name' => $this->unit->name,
            ...$this->timestamps(),
        ];
    }
}
