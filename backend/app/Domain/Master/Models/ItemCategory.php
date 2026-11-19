<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Core\Traits\Auditable;
use Database\Factories\ItemCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $item_type
 * @property int|null $inventory_account_id
 * @property int|null $cogs_account_id
 * @property int|null $sales_account_id
 */
class ItemCategory extends Model
{
    public const TYPES = ['trading', 'raw_material', 'finished_good'];

    /** @use HasFactory<ItemCategoryFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'item_type',
        'inventory_account_id',
        'cogs_account_id',
        'sales_account_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'inventory_account_id' => 'integer',
            'cogs_account_id' => 'integer',
            'sales_account_id' => 'integer',
        ];
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
    }

    protected static function newFactory(): ItemCategoryFactory
    {
        return ItemCategoryFactory::new();
    }
}
