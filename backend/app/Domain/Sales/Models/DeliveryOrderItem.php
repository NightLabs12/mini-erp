<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $delivery_order_id
 * @property int|null $sales_order_item_id
 * @property int $item_id
 * @property int $unit_id
 * @property int $line_no
 * @property string $qty
 * @property string $qty_base
 * @property string $cogs_unit_cost_base
 * @property string $total_cogs
 * @property string $invoiced_qty_base
 */
class DeliveryOrderItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'delivery_order_id',
        'sales_order_item_id',
        'item_id',
        'unit_id',
        'line_no',
        'qty',
        'qty_base',
        'cogs_unit_cost_base',
        'total_cogs',
        'invoiced_qty_base',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_order_id' => 'integer',
            'sales_order_item_id' => 'integer',
            'item_id' => 'integer',
            'unit_id' => 'integer',
            'line_no' => 'integer',
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'cogs_unit_cost_base' => 'decimal:4',
            'total_cogs' => 'decimal:2',
            'invoiced_qty_base' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<DeliveryOrder, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'delivery_order_id');
    }

    /** @return BelongsTo<SalesOrderItem, $this> */
    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
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
