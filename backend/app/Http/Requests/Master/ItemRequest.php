<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Item beserta konversi satuannya.
 *
 * Konversi dikirim sekaligus karena satu item selalu punya definisi satuan
 * yang utuh. Aturan yang dijaga:
 * - base unit harus ada dan tidak boleh muncul lagi di daftar konversi;
 * - setiap unit konversi unik di dalam payload (rule distinct);
 * - factor_to_base harus > 0.
 */
final class ItemRequest extends FormRequest
{
    use ResolvesRouteModel;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $itemId = $this->routeModelId('item');

        return [
            'sku' => [
                $required,
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('items', 'sku')->whereNull('deleted_at')->ignore($itemId),
            ],
            'name' => [$required, 'string', 'max:150'],
            'category_id' => [
                $required,
                'integer',
                Rule::exists('item_categories', 'id')->whereNull('deleted_at'),
            ],
            'base_unit_id' => [
                $required,
                'integer',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
            ],
            'min_stock' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'conversions' => ['sometimes', 'array', 'max:20'],
            'conversions.*.unit_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
            ],
            'conversions.*.factor_to_base' => ['required', 'numeric', 'gt:0'],
            'conversions.*.is_purchase_default' => ['sometimes', 'boolean'],
            'conversions.*.is_sales_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sku.unique' => 'SKU sudah dipakai item lain.',
            'sku.regex' => 'SKU hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.',
            'conversions.*.unit_id.distinct' => 'Satu satuan tidak boleh muncul lebih dari sekali.',
            'conversions.*.factor_to_base.gt' => 'Faktor konversi harus lebih besar dari nol.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $baseUnitId = $this->input('base_unit_id');
                $conversions = $this->input('conversions');

                if (! is_array($conversions) || $baseUnitId === null) {
                    return;
                }

                foreach ($conversions as $index => $conversion) {
                    $unitId = is_array($conversion) ? ($conversion['unit_id'] ?? null) : null;

                    if ($unitId !== null && (string) $unitId === (string) $baseUnitId) {
                        $validator->errors()->add(
                            "conversions.{$index}.unit_id",
                            'Satuan base tidak perlu ditulis ulang sebagai konversi.',
                        );
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => strtoupper(trim($this->input('sku')))]);
        }
    }
}
