<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_opname_id
 * @property int $item_id
 * @property int $unit_id
 * @property int $line_no
 * @property string $system_qty_base
 * @property string $counted_qty_base
 * @property string $diff_qty_base
 * @property string|null $unit_cost
 * @property string|null $total_value
 */
class StockOpnameItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'stock_opname_id',
        'item_id',
        'unit_id',
        'line_no',
        'system_qty_base',
        'counted_qty_base',
        'diff_qty_base',
        'unit_cost',
        'total_value',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stock_opname_id' => 'integer',
            'item_id' => 'integer',
            'unit_id' => 'integer',
            'line_no' => 'integer',
            'system_qty_base' => 'decimal:4',
            'counted_qty_base' => 'decimal:4',
            'diff_qty_base' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<StockOpname, $this> */
    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
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
