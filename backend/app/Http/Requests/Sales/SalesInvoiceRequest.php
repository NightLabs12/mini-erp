<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SalesInvoiceRequest extends FormRequest
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
            'customer_id' => [
                $required,
                'integer',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],
            'mode' => ['sometimes', 'string', Rule::in(['draft', 'final'])],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items' => [$required, 'array', 'min:1', 'max:200'],
            'items.*.delivery_order_item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('delivery_order_items', 'id'),
            ],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mode.in' => 'Mode invoice harus draft atau final.',
            'items.min' => 'Sales invoice minimal memiliki satu baris item.',
            'items.*.delivery_order_item_id.distinct' => 'Satu baris DO tidak boleh muncul lebih dari sekali.',
            'items.*.qty.gt' => 'Qty harus lebih besar dari nol.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (is_array($items)) {
            foreach ($items as $index => $item) {
                if (is_array($item) && isset($item['qty']) && is_numeric($item['qty']) && ! is_bool($item['qty'])) {
                    $items[$index]['qty'] = (string) $item['qty'];
                }
            }

            $this->merge(['items' => $items]);
        }

        if ($this->input('tax_rate') !== null && is_numeric($this->input('tax_rate'))) {
            $this->merge(['tax_rate' => (string) $this->input('tax_rate')]);
        }
    }
}
