<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int $item_id
 * @property int $unit_id
 * @property int $line_no
 * @property string $qty
 * @property string $qty_base
 * @property string $unit_price
 * @property string $discount_pct
 * @property string $unit_price_base
 * @property string $line_total
 * @property string $delivered_qty_base
 * @property string $invoiced_qty_base
 */
class SalesOrderItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'sales_order_id',
        'item_id',
        'unit_id',
        'line_no',
        'qty',
        'qty_base',
        'unit_price',
        'discount_pct',
        'unit_price_base',
        'line_total',
        'delivered_qty_base',
        'invoiced_qty_base',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sales_order_id' => 'integer',
            'item_id' => 'integer',
            'unit_id' => 'integer',
            'line_no' => 'integer',
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_pct' => 'decimal:2',
            'unit_price_base' => 'decimal:4',
            'line_total' => 'decimal:2',
            'delivered_qty_base' => 'decimal:4',
            'invoiced_qty_base' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
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
