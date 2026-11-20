<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dipakai untuk store dan update; aturan required hanya berlaku saat create.
 */
final class UnitRequest extends FormRequest
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
        $unitId = $this->routeModelId('unit');

        return [
            'code' => [
                $required,
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('units', 'code')->whereNull('deleted_at')->ignore($unitId),
            ],
            'name' => [$required, 'string', 'max:60'],
            'decimal_places' => ['sometimes', 'integer', 'min:0', 'max:4'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, garis bawah, atau tanda hubung.',
            'code.unique' => 'Kode satuan sudah dipakai.',
            'decimal_places.max' => 'Presisi satuan maksimal 4 desimal.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
