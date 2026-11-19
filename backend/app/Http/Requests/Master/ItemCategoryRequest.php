<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Master\Models\ItemCategory;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ItemCategoryRequest extends FormRequest
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
        $categoryId = $this->routeModelId('itemCategory');

        return [
            'code' => [
                $required,
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('item_categories', 'code')->whereNull('deleted_at')->ignore($categoryId),
            ],
            'name' => [$required, 'string', 'max:80'],
            'item_type' => [$required, Rule::in(ItemCategory::TYPES)],
            'inventory_account_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cogs_account_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sales_account_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'Kode kategori sudah dipakai.',
            'item_type.in' => 'Jenis item harus trading, raw_material, atau finished_good.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
