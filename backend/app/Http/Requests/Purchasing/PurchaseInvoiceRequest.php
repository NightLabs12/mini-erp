<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PurchaseInvoiceRequest extends FormRequest
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
                $required,
                'integer',
                Rule::exists('suppliers', 'id')->whereNull('deleted_at'),
            ],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items' => [$required, 'array', 'min:1', 'max:200'],
            'items.*.goods_receipt_item_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('goods_receipt_items', 'id'),
            ],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.min' => 'Purchase invoice minimal memiliki satu baris item.',
            'items.*.goods_receipt_item_id.distinct' => 'Satu baris GR tidak boleh muncul lebih dari sekali.',
            'items.*.qty.gt' => 'Qty harus lebih besar dari nol.',
            'items.*.unit_price.min' => 'Harga satuan tidak boleh negatif.',
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

                foreach (['qty', 'unit_price'] as $field) {
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
