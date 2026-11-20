<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $goods_receipt_id
 * @property int|null $purchase_order_item_id
 * @property int $item_id
 * @property int $unit_id
 * @property int $line_no
 * @property string $qty
 * @property string $qty_base
 * @property string $unit_cost_base
 * @property string $total_value
 * @property string $invoiced_qty_base
 */
class GoodsReceiptItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'item_id',
        'unit_id',
        'line_no',
        'qty',
        'qty_base',
        'unit_cost_base',
        'total_value',
        'invoiced_qty_base',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'goods_receipt_id' => 'integer',
            'purchase_order_item_id' => 'integer',
            'item_id' => 'integer',
            'unit_id' => 'integer',
            'line_no' => 'integer',
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_cost_base' => 'decimal:4',
            'total_value' => 'decimal:2',
            'invoiced_qty_base' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /** @return BelongsTo<PurchaseOrderItem, $this> */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
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

    /** @return HasMany<PurchaseInvoiceItem, $this> */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }
}
