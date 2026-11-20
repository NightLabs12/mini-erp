<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Core\Models\User;
use App\Domain\Core\Traits\Auditable;
use App\Domain\Master\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dokumen transfer antar gudang.
 *
 * status mengikuti state machine dokumen: draft -> approved, dan approved ->
 * draft lewat disapprove selama aturan SPEC 3.3(c) terpenuhi.
 *
 * @property int $id
 * @property string $doc_no
 * @property \Illuminate\Support\Carbon $doc_date
 * @property int $period_id
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property string $status
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 */
class WarehouseTransfer extends Model
{
    use Auditable, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = [
        'doc_no',
        'doc_date',
        'period_id',
        'from_warehouse_id',
        'to_warehouse_id',
        'status',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'doc_date' => 'date:Y-m-d',
            'period_id' => 'integer',
            'from_warehouse_id' => 'integer',
            'to_warehouse_id' => 'integer',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    /** @return HasMany<WarehouseTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WarehouseTransferItem::class);
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
