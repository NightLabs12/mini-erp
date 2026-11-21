<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parameter generik seluruh laporan. Setiap laporan memakai subset yang
 * relevan; validasi keberadaan parameter wajib dilakukan di ReportRegistry.
 */
final class ReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'format' => ['sometimes', 'string', Rule::in(['json', 'xlsx', 'pdf'])],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'as_of' => ['sometimes', 'date'],
            'compare_from' => ['sometimes', 'date'],
            'compare_to' => ['sometimes', 'date', 'after_or_equal:compare_from'],
            'compare_as_of' => ['sometimes', 'date'],
            'account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('accounts', 'id')],
            'item_id' => ['sometimes', 'nullable', 'integer', Rule::exists('items', 'id')],
            'warehouse_id' => ['sometimes', 'nullable', 'integer', Rule::exists('warehouses', 'id')],
            'supplier_id' => ['sometimes', 'nullable', 'integer', Rule::exists('suppliers', 'id')],
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')],
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
            'source_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'Tanggal akhir tidak boleh lebih awal dari tanggal mulai.',
            'compare_to.after_or_equal' => 'Tanggal pembanding akhir tidak boleh lebih awal dari pembanding mulai.',
            'format.in' => 'Format harus json, xlsx, atau pdf.',
        ];
    }
}
