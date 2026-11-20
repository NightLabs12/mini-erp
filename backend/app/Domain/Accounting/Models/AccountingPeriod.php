<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Periode akuntansi bulanan.
 *
 * @property int $id
 * @property int $year
 * @property int $month
 * @property string $status
 * @property int|null $closed_by
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property int|null $reopened_by
 * @property \Illuminate\Support\Carbon|null $reopened_at
 */
class AccountingPeriod extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /** @var list<string> */
    protected $fillable = [
        'year',
        'month',
        'status',
        'closed_by',
        'closed_at',
        'reopened_by',
        'reopened_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'closed_by' => 'integer',
            'closed_at' => 'datetime',
            'reopened_by' => 'integer',
            'reopened_at' => 'datetime',
        ];
    }

    /** @return HasMany<Journal, $this> */
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class, 'period_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function firstDate(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1)->startOfDay();
    }

    public function lastDate(): CarbonImmutable
    {
        return $this->firstDate()->endOfMonth()->startOfDay();
    }

    public function label(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function previous(): ?self
    {
        $date = $this->firstDate()->subMonth();

        return self::query()
            ->where('year', $date->year)
            ->where('month', $date->month)
            ->first();
    }
}
