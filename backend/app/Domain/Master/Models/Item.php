<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Core\Traits\Auditable;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $sku
 * @property string $name
 * @property int $category_id
 * @property int $base_unit_id
 * @property string $min_stock
 * @property bool $is_active
 */
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'sku',
        'name',
        'category_id',
        'base_unit_id',
        'min_stock',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'base_unit_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ItemCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /** @return HasMany<ItemUnitConversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(ItemUnitConversion::class);
    }

    /** @return HasMany<SellingPrice, $this> */
    public function sellingPrices(): HasMany
    {
        return $this->hasMany(SellingPrice::class);
    }

    protected static function newFactory(): ItemFactory
    {
        return ItemFactory::new();
    }
}
