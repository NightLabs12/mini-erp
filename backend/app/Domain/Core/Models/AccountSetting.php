<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pemetaan key akun ke akun COA. Baris diisi pada Fase 2 bersama tabel accounts.
 *
 * @property int $id
 * @property string $key
 * @property int|null $account_id
 */
class AccountSetting extends Model
{
    protected $fillable = [
        'key',
        'account_id',
        'description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
        ];
    }
}
