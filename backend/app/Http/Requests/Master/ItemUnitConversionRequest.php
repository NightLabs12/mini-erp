<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Master\Models\Item;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ItemUnitConversionRequest extends FormRequest
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
        $conversionId = $this->routeModelId('conversion');
        $itemId = $this->routeModelId('item');

        $item = $this->route('item');
        $baseUnitId = $item instanceof Item ? $item->base_unit_id : null;

        return [
            'unit_id' => [
                $required,
                'integer',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
                Rule::unique('item_unit_conversions', 'unit_id')
                    ->where('item_id', $itemId)
                    ->ignore($conversionId),
                Rule::notIn(array_filter([$baseUnitId])),
            ],
            'factor_to_base' => [$required, 'numeric', 'gt:0'],
            'is_purchase_default' => ['sometimes', 'boolean'],
            'is_sales_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'unit_id.unique' => 'Satuan ini sudah punya konversi untuk item tersebut.',
            'unit_id.not_in' => 'Satuan base tidak perlu ditulis ulang sebagai konversi.',
            'factor_to_base.gt' => 'Faktor konversi harus lebih besar dari nol.',
        ];
    }
}
