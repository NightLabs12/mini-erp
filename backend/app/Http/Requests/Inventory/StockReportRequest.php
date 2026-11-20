<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter Posisi Stok dan Mutasi Stok. Default rentang adalah bulan berjalan.
 */
final class StockReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('items', 'id')->whereNull('deleted_at'),
            ],
            'warehouse_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->whereNull('deleted_at'),
            ],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
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
