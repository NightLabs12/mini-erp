<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Domain\Accounting\Models\Account;
use App\Http\Requests\Concerns\ResolvesRouteModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Chart of Accounts.
 *
 * `level` tidak diterima dari klien; controller menghitungnya dari parent agar
 * hierarki tidak dapat dibuat tidak konsisten.
 */
final class AccountRequest extends FormRequest
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
        $accountId = $this->routeModelId('account');

        return [
            'code' => [
                $required,
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('accounts', 'code')->ignore($accountId),
            ],
            'name' => [$required, 'string', 'max:120'],
            'type' => [$required, Rule::in(Account::TYPES)],
            'normal_balance' => [$required, Rule::in([Account::NORMAL_DEBIT, Account::NORMAL_CREDIT])],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id'),
                Rule::notIn(array_filter([$accountId])),
            ],
            'is_postable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.unique' => 'Kode akun sudah dipakai.',
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.',
            'type.in' => 'Tipe akun tidak dikenal.',
            'normal_balance.in' => 'Saldo normal harus D atau C.',
            'parent_id.not_in' => 'Akun tidak boleh menjadi induk dirinya sendiri.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }

        if (is_string($this->input('normal_balance'))) {
            $this->merge(['normal_balance' => strtoupper(trim($this->input('normal_balance')))]);
        }
    }
}
