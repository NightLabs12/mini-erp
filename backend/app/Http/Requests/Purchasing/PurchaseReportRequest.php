<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PurchaseReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'supplier_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'Tanggal akhir tidak boleh lebih awal dari tanggal mulai.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'from' => $this->input('from', now()->startOfMonth()->toDateString()),
            'to' => $this->input('to', now()->endOfMonth()->toDateString()),
        ]);
    }
}
