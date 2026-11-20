<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'doc_date' => [$required, 'date'],
            'supplier_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->whereNull('deleted_at'),
            ],
            'warehouse_id' => [
                $required,
                'integer',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items' => [$required, 'array', 'min:1', 'max:200'],
            'items.*.item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('items', 'id')->whereNull('deleted_at'),
            ],
            'items.*.unit_id' => [
                'required',
                'integer',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
            ],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.min' => 'Purchase request minimal memiliki satu baris item.',
            'items.*.item_id.distinct' => 'Satu item tidak boleh muncul lebih dari sekali.',
            'items.*.qty.gt' => 'Qty harus lebih besar dari nol.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (is_array($item) && isset($item['qty']) && is_numeric($item['qty']) && ! is_bool($item['qty'])) {
                $items[$index]['qty'] = (string) $item['qty'];
            }
        }

        $this->merge(['items' => $items]);
    }
}
