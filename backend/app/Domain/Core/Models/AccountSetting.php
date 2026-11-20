<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use App\Domain\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemetaan key akun ke akun COA. Sumber tunggal AccountMapper, bukan hardcode
 * kode akun di service dokumen.
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

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
