<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SupplierPaymentRequest extends FormRequest
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
            'method' => [$required, 'string', Rule::in(['cash', 'bank'])],
            'account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('is_postable', true)->where('is_active', true),
            ],
            'amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'allocations' => [$required, 'array', 'min:1', 'max:100'],
            'allocations.*.purchase_invoice_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('purchase_invoices', 'id')->whereNull('deleted_at'),
            ],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'method.in' => 'Metode pembayaran harus cash atau bank.',
            'allocations.min' => 'Pembayaran minimal memiliki satu alokasi invoice.',
            'allocations.*.purchase_invoice_id.distinct' => 'Satu invoice tidak boleh dialokasikan dua kali.',
            'allocations.*.amount.gt' => 'Nominal alokasi harus lebih besar dari nol.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $allocations = $this->input('allocations');

        if (! is_array($allocations)) {
            return;
        }

        foreach ($allocations as $index => $allocation) {
            if (is_array($allocation) && isset($allocation['amount'])
                && is_numeric($allocation['amount']) && ! is_bool($allocation['amount'])) {
                $allocations[$index]['amount'] = (string) $allocation['amount'];
            }
        }

        $this->merge(['allocations' => $allocations]);

        if ($this->input('amount') !== null && is_numeric($this->input('amount'))) {
            $this->merge(['amount' => (string) $this->input('amount')]);
        }
    }
}
