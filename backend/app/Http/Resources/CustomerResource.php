<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Master\Models\Customer;
use Illuminate\Http\Request;

final class CustomerResource extends ApiResource
{
    private readonly Customer $customer;

    public function __construct(Customer $customer)
    {
        parent::__construct($customer);

        $this->customer = $customer;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->customer->getKey(),
            'code' => $this->customer->code,
            'name' => $this->customer->name,
            'address' => $this->customer->address,
            'phone' => $this->customer->phone,
            'email' => $this->customer->email,
            'tax_id' => $this->customer->tax_id,
            'payment_term_days' => $this->customer->payment_term_days,
            'credit_limit' => $this->customer->credit_limit,
            ...$this->timestamps(),
        ];
    }
}
