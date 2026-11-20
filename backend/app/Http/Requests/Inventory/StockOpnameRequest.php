<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stock opname: qty fisik per item dalam satuan input.
 */
final class StockOpnameRequest extends FormRequest
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
            'warehouse_id' => [
                $required,
                'integer',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items' => [$required, 'array', 'min:1', 'max:500'],
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
            'items.*.counted_qty' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.min' => 'Stock opname minimal memiliki satu baris item.',
            'items.*.item_id.distinct' => 'Satu item tidak boleh muncul lebih dari sekali.',
            'items.*.counted_qty.min' => 'Qty hitung fisik tidak boleh negatif.',
            'items.*.counted_qty.decimal' => 'Qty hitung fisik maksimal 4 desimal.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item) || ! isset($item['counted_qty'])) {
                continue;
            }

            if (is_numeric($item['counted_qty']) && ! is_bool($item['counted_qty'])) {
                $items[$index]['counted_qty'] = (string) $item['counted_qty'];
            }
        }

        $this->merge(['items' => $items]);
    }
}
