<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Models\Warehouse;
use Illuminate\Http\Request;

final class WarehouseResource extends ApiResource
{
    private readonly Warehouse $warehouse;

    public function __construct(Warehouse $warehouse)
    {
        parent::__construct($warehouse);

        $this->warehouse = $warehouse;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->warehouse->getKey(),
            'code' => $this->warehouse->code,
            'name' => $this->warehouse->name,
            'address' => $this->warehouse->address,
            'is_active' => (bool) $this->warehouse->is_active,
            ...$this->timestamps(),
        ];
    }
}
