<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use Database\Factories\ItemUnitConversionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $item_id
 * @property int $unit_id
 * @property string $factor_to_base
 * @property bool $is_purchase_default
 * @property bool $is_sales_default
 */
class ItemUnitConversion extends Model
{
    /** @use HasFactory<ItemUnitConversionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'item_id',
        'unit_id',
        'factor_to_base',
        'is_purchase_default',
        'is_sales_default',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'unit_id' => 'integer',
            'is_purchase_default' => 'boolean',
            'is_sales_default' => 'boolean',
        ];
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

    protected static function newFactory(): ItemUnitConversionFactory
    {
        return ItemUnitConversionFactory::new();
    }
}
