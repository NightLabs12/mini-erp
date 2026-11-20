<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $warehouse_transfer_id
 * @property int $item_id
 * @property int $unit_id
 * @property int $line_no
 * @property string $qty
 * @property string $qty_base
 * @property string|null $unit_cost_base
 * @property string|null $total_value
 */
class WarehouseTransferItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'warehouse_transfer_id',
        'item_id',
        'unit_id',
        'line_no',
        'qty',
        'qty_base',
        'unit_cost_base',
        'total_value',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'warehouse_transfer_id' => 'integer',
            'item_id' => 'integer',
            'unit_id' => 'integer',
            'line_no' => 'integer',
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_cost_base' => 'decimal:4',
            'total_value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<WarehouseTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(WarehouseTransfer::class, 'warehouse_transfer_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
