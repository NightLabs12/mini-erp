<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DeliveryOrderRequest extends FormRequest
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
            'sales_order_id' => [
                $required,
                'integer',
                Rule::exists('sales_orders', 'id')->whereNull('deleted_at'),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items' => [$required, 'array', 'min:1', 'max:200'],
            'items.*.sales_order_item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('sales_order_items', 'id'),
            ],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.min' => 'Delivery order minimal memiliki satu baris item.',
            'items.*.sales_order_item_id.distinct' => 'Satu baris SO tidak boleh muncul lebih dari sekali.',
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
