<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $method
 * @property string $path
 * @property int $status
 * @property int $duration_ms
 */
class ApiRequestLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'method',
        'path',
        'status',
        'duration_ms',
        'ip',
        'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
