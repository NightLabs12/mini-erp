<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Models\Supplier;
use Illuminate\Http\Request;

final class SupplierResource extends ApiResource
{
    private readonly Supplier $supplier;

    public function __construct(Supplier $supplier)
    {
        parent::__construct($supplier);

        $this->supplier = $supplier;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->supplier->getKey(),
            'code' => $this->supplier->code,
            'name' => $this->supplier->name,
            'address' => $this->supplier->address,
            'phone' => $this->supplier->phone,
            'email' => $this->supplier->email,
            'tax_id' => $this->supplier->tax_id,
            'payment_term_days' => $this->supplier->payment_term_days,
            ...$this->timestamps(),
        ];
    }
}
