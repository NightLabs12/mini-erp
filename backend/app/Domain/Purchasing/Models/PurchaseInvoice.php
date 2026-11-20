<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Accounting\Models\Journal;
use App\Domain\Core\Models\User;
use App\Domain\Core\Traits\Auditable;
use App\Domain\Master\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $doc_no
 * @property \Illuminate\Support\Carbon $doc_date
 * @property int $period_id
 * @property int $supplier_id
 * @property \Illuminate\Support\Carbon $due_date
 * @property string $status
 * @property string $subtotal
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $total
 * @property string $paid_amount
 * @property string|null $notes
 * @property int|null $journal_id
 * @property \Illuminate\Support\Carbon|null $approved_at
 */
class PurchaseInvoice extends Model
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
        'supplier_id',
        'due_date',
        'status',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total',
        'paid_amount',
        'notes',
        'journal_id',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'doc_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'period_id' => 'integer',
            'supplier_id' => 'integer',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'journal_id' => 'integer',
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

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /** @return HasMany<PurchaseInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    /** @return HasMany<SupplierPaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
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

    public function remaining(): string
    {
        return bcsub((string) $this->total, (string) $this->paid_amount, 2);
    }
}
