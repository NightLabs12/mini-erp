<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Konversi PR menjadi PO. Semua field opsional; service memakai nilai PR
 * sebagai default, tetapi supplier wajib tersedia dari PR atau payload.
 */
final class ConvertPurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'doc_date' => ['sometimes', 'date'],
            'supplier_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->whereNull('deleted_at'),
            ],
            'expected_date' => ['sometimes', 'nullable', 'date'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:200'],
            'items.*.item_id' => [
                'required_with:items',
                'integer',
                'distinct',
                Rule::exists('items', 'id')->whereNull('deleted_at'),
            ],
            'items.*.unit_id' => [
                'required_with:items',
                'integer',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
            ],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0', 'decimal:0,4'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0', 'decimal:0,4'],
            'items.*.discount_pct' => ['sometimes', 'numeric', 'between:0,100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (is_array($items)) {
            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (['qty', 'unit_price', 'discount_pct'] as $field) {
                    if (isset($item[$field]) && is_numeric($item[$field]) && ! is_bool($item[$field])) {
                        $items[$index][$field] = (string) $item[$field];
                    }
                }
            }

            $this->merge(['items' => $items]);
        }

        if ($this->input('tax_rate') !== null && is_numeric($this->input('tax_rate'))) {
            $this->merge(['tax_rate' => (string) $this->input('tax_rate')]);
        }
    }
}
