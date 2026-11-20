<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Akun Chart of Accounts.
 *
 * Akun induk tidak postable; jurnal hanya boleh memakai akun postable dan
 * aktif. Hierarki dibentuk parent_id dan level agar laporan dapat menampilkan
 * pohon tanpa rekursi.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $normal_balance
 * @property int|null $parent_id
 * @property int $level
 * @property bool $is_postable
 * @property bool $is_active
 */
class Account extends Model
{
    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'];

    public const NORMAL_DEBIT = 'D';

    public const NORMAL_CREDIT = 'C';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'type',
        'normal_balance',
        'parent_id',
        'level',
        'is_postable',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'level' => 'integer',
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<JournalLine, $this> */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @param Builder<self> $query */
    public function scopePostable(Builder $query): void
    {
        $query->where('is_postable', true)->where('is_active', true);
    }
}
