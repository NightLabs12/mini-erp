<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Support\Math\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Jurnal manual. Debit/credit dinormalisasi menjadi string agar tidak ada
 * float yang masuk ke JournalService.
 */
final class JournalRequest extends FormRequest
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
            'journal_date' => [$required, 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines' => [$required, 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('is_postable', true)->where('is_active', true),
            ],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines.*.partner_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'lines.*.partner_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.min' => 'Jurnal minimal terdiri dari dua baris.',
            'lines.*.account_id.exists' => 'Akun tidak ditemukan atau bukan akun postable.',
            'lines.*.debit.decimal' => 'Nilai debit maksimal 2 desimal.',
            'lines.*.credit.decimal' => 'Nilai credit maksimal 2 desimal.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lines = $this->input('lines');

                if (! is_array($lines)) {
                    return;
                }

                foreach ($lines as $index => $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $debitValue = $line['debit'] ?? '0';
                    $creditValue = $line['credit'] ?? '0';

                    if (! is_string($debitValue) && ! is_int($debitValue)) {
                        continue;
                    }

                    if (! is_string($creditValue) && ! is_int($creditValue)) {
                        continue;
                    }

                    $debit = Decimal::round($debitValue, Decimal::MONEY_SCALE);
                    $credit = Decimal::round($creditValue, Decimal::MONEY_SCALE);

                    $hasDebit = Decimal::compare($debit, '0', Decimal::MONEY_SCALE) > 0;
                    $hasCredit = Decimal::compare($credit, '0', Decimal::MONEY_SCALE) > 0;

                    if ($hasDebit === $hasCredit) {
                        $validator->errors()->add(
                            "lines.{$index}.debit",
                            'Isi tepat satu nilai debit atau credit pada setiap baris.',
                        );
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            foreach (['debit', 'credit'] as $field) {
                if (isset($line[$field]) && is_numeric($line[$field]) && ! is_bool($line[$field])) {
                    $lines[$index][$field] = (string) $line[$field];
                }
            }
        }

        $this->merge(['lines' => $lines]);
    }
}
