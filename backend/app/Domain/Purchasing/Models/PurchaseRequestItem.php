<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_request_id
 * @property int $item_id
 * @property int $unit_id
 * @property int $line_no
 * @property string $qty
 * @property string $qty_base
 */
class PurchaseRequestItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'purchase_request_id',
        'item_id',
        'unit_id',
        'line_no',
        'qty',
        'qty_base',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_request_id' => 'integer',
            'item_id' => 'integer',
            'unit_id' => 'integer',
            'line_no' => 'integer',
            'qty' => 'decimal:4',
            'qty_base' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<PurchaseRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
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
