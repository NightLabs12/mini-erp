<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Core\Models\User;
use App\Domain\Core\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Header jurnal double-entry.
 *
 * status:
 * - draft: jurnal manual yang masih boleh diubah, belum masuk laporan;
 * - posted: sudah diposting dan menjadi sumber laporan;
 * - reversed: jurnal posted yang sudah dibalik jurnal lain.
 *
 * @property int $id
 * @property string|null $journal_no
 * @property \Illuminate\Support\Carbon $journal_date
 * @property int $period_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $description
 * @property string $status
 * @property string $total_debit
 * @property string $total_credit
 * @property int|null $reversal_of_id
 * @property \Illuminate\Support\Carbon|null $approved_at
 */
class Journal extends Model
{
    use Auditable, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    /** @var list<string> */
    protected $fillable = [
        'journal_no',
        'journal_date',
        'period_id',
        'source_type',
        'source_id',
        'description',
        'status',
        'total_debit',
        'total_credit',
        'reversal_of_id',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'journal_date' => 'date:Y-m-d',
            'period_id' => 'integer',
            'source_id' => 'integer',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'reversal_of_id' => 'integer',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AccountingPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return BelongsTo<self, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return HasMany<self, $this> */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
