<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_invoice_id
 * @property int $goods_receipt_item_id
 * @property int $item_id
 * @property int $unit_id
 * @property int $line_no
 * @property string $qty
 * @property string $qty_base
 * @property string $unit_price
 * @property string $unit_price_base
 * @property string $gr_unit_cost
 * @property string $gr_value
 * @property string $total_value
 */
class PurchaseInvoiceItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'purchase_invoice_id',
        'goods_receipt_item_id',
        'item_id',
        'unit_id',
        'line_no',
        'qty',
        'qty_base',
        'unit_price',
        'unit_price_base',
        'gr_unit_cost',
        'gr_value',
        'total_value',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_invoice_id' => 'integer',
            'goods_receipt_item_id' => 'integer',
            'item_id' => 'integer',
            'unit_id' => 'integer',
            'line_no' => 'integer',
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'unit_price_base' => 'decimal:4',
            'gr_unit_cost' => 'decimal:4',
            'gr_value' => 'decimal:2',
            'total_value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PurchaseInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    /** @return BelongsTo<GoodsReceiptItem, $this> */
    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class, 'goods_receipt_item_id');
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
