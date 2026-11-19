<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SellingPriceLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'unit_id' => ['sometimes', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'date' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function lookupDate(): string
    {
        $date = $this->validated()['date'] ?? null;

        return is_string($date) ? $date : now()->toDateString();
    }
}
