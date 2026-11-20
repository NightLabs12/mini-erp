<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Core\Models\User;
use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ledger stok append-only.
 *
 * Model ini sengaja tidak memakai SoftDeletes dan tidak memiliki updated_at.
 * Trigger PostgreSQL menolak UPDATE dan DELETE; pembalikan selalu berupa baris
 * baru dengan reversal_of_id.
 *
 * @property int $id
 * @property int $item_id
 * @property int $warehouse_id
 * @property \Illuminate\Support\Carbon $movement_date
 * @property string $doc_type
 * @property int|null $doc_id
 * @property int|null $doc_line_id
 * @property string $qty_in_base
 * @property string $qty_out_base
 * @property string $unit_cost
 * @property string $total_value
 * @property string $balance_qty
 * @property string $balance_value
 * @property string $avg_cost_after
 * @property int|null $reversal_of_id
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
class StockLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'stock_ledger';

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'warehouse_id',
        'movement_date',
        'doc_type',
        'doc_id',
        'doc_line_id',
        'qty_in_base',
        'qty_out_base',
        'unit_cost',
        'total_value',
        'balance_qty',
        'balance_value',
        'avg_cost_after',
        'reversal_of_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'warehouse_id' => 'integer',
            'movement_date' => 'date:Y-m-d',
            'doc_id' => 'integer',
            'doc_line_id' => 'integer',
            'qty_in_base' => 'decimal:4',
            'qty_out_base' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_value' => 'decimal:2',
            'balance_qty' => 'decimal:4',
            'balance_value' => 'decimal:2',
            'avg_cost_after' => 'decimal:4',
            'reversal_of_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
}
