<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo stok berjalan per item dan gudang dalam satuan base.
 *
 * @property int $id
 * @property int $item_id
 * @property int $warehouse_id
 * @property string $qty_base
 * @property string $avg_cost
 * @property string $value
 */
class StockBalance extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'warehouse_id',
        'qty_base',
        'avg_cost',
        'value',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'warehouse_id' => 'integer',
            'qty_base' => 'decimal:4',
            'avg_cost' => 'decimal:4',
            'value' => 'decimal:2',
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
}
