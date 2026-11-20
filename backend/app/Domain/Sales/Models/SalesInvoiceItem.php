<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sales_invoice_id
 * @property int $delivery_order_item_id
 * @property int $item_id
 * @property int $unit_id
 * @property int $line_no
 * @property string $qty
 * @property string $qty_base
 * @property string $unit_price
 * @property string $unit_price_base
 * @property string $total_value
 */
class SalesInvoiceItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'sales_invoice_id',
        'delivery_order_item_id',
        'item_id',
        'unit_id',
        'line_no',
        'qty',
        'qty_base',
        'unit_price',
        'unit_price_base',
        'total_value',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sales_invoice_id' => 'integer',
            'delivery_order_item_id' => 'integer',
            'item_id' => 'integer',
            'unit_id' => 'integer',
            'line_no' => 'integer',
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'unit_price_base' => 'decimal:4',
            'total_value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SalesInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /** @return BelongsTo<DeliveryOrderItem, $this> */
    public function deliveryOrderItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrderItem::class, 'delivery_order_item_id');
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
