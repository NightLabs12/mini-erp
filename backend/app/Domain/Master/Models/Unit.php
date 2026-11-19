<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Core\Traits\Auditable;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['code', 'name'];

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'base_unit_id');
    }

    /** @return HasMany<ItemUnitConversion, $this> */
    public function conversions(): HasMany
    {
        return $this->hasMany(ItemUnitConversion::class);
    }

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }
}
