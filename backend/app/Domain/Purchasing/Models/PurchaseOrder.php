<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Core\Models\User;
use App\Domain\Core\Traits\Auditable;
use App\Domain\Master\Models\Supplier;
use App\Domain\Master\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Purchase Order. closed_manually membedakan penutupan manual dari penutupan
 * otomatis saat seluruh qty sudah diterima.
 *
 * @property int $id
 * @property string $doc_no
 * @property \Illuminate\Support\Carbon $doc_date
 * @property int $period_id
 * @property int|null $purchase_request_id
 * @property int $supplier_id
 * @property int $warehouse_id
 * @property \Illuminate\Support\Carbon|null $expected_date
 * @property string $status
 * @property string $subtotal
 * @property string $discount_amount
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $total
 * @property bool $closed_manually
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $approved_at
 */
class PurchaseOrder extends Model
{
    use Auditable, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = [
        'doc_no',
        'doc_date',
        'period_id',
        'purchase_request_id',
        'supplier_id',
        'warehouse_id',
        'expected_date',
        'status',
        'subtotal',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'total',
        'closed_manually',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'doc_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'period_id' => 'integer',
            'purchase_request_id' => 'integer',
            'supplier_id' => 'integer',
            'warehouse_id' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'closed_manually' => 'boolean',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<PurchaseRequest, $this> */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
