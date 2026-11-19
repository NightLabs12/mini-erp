<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomerRequest extends FormRequest
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
        $customerId = $this->routeModelId('customer');

        return [
            'code' => [
                $required,
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('customers', 'code')->whereNull('deleted_at')->ignore($customerId),
            ],
            'name' => [$required, 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'payment_term_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'Kode customer sudah dipakai.',
            'credit_limit.min' => 'Batas kredit tidak boleh negatif.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
