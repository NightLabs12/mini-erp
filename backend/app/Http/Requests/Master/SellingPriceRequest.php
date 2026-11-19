<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SellingPriceRequest extends FormRequest
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
            'item_id' => [
                $required,
                'integer',
                Rule::exists('items', 'id')->whereNull('deleted_at'),
            ],
            'unit_id' => [
                $required,
                'integer',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
            ],
            'customer_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->whereNull('deleted_at'),
            ],
            'price' => [$required, 'numeric', 'gt:0'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'price.gt' => 'Harga jual harus lebih besar dari nol.',
            'valid_to.after_or_equal' => 'Tanggal akhir tidak boleh lebih awal dari tanggal mulai.',
        ];
    }
}
