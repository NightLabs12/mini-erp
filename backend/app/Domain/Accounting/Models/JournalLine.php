<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu sisi jurnal. Hanya salah satu debit/credit yang boleh > 0; aturan ini
 * juga dijaga CHECK constraint di database.
 *
 * @property int $id
 * @property int $journal_id
 * @property int $account_id
 * @property \Illuminate\Support\Carbon $journal_date
 * @property int $line_no
 * @property string $debit
 * @property string $credit
 * @property string|null $description
 * @property string|null $partner_type
 * @property int|null $partner_id
 */
class JournalLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'journal_id',
        'account_id',
        'journal_date',
        'line_no',
        'debit',
        'credit',
        'description',
        'partner_type',
        'partner_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'journal_id' => 'integer',
            'account_id' => 'integer',
            'journal_date' => 'date:Y-m-d',
            'line_no' => 'integer',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'partner_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
