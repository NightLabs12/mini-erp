<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Accounting\Models\Journal;
use App\Domain\Core\Models\User;
use App\Domain\Core\Traits\Auditable;
use App\Domain\Master\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $doc_no
 * @property \Illuminate\Support\Carbon $doc_date
 * @property int $period_id
 * @property int $customer_id
 * @property \Illuminate\Support\Carbon $due_date
 * @property string $status
 * @property string $mode
 * @property string $subtotal
 * @property string $tax_rate
 * @property string $tax_amount
 * @property string $total
 * @property string $paid_amount
 * @property string|null $notes
 * @property int|null $journal_id
 * @property \Illuminate\Support\Carbon|null $approved_at
 */
class SalesInvoice extends Model
{
    use Auditable, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const MODE_DRAFT = 'draft';

    public const MODE_FINAL = 'final';

    /** @var list<string> */
    protected $fillable = [
        'doc_no',
        'doc_date',
        'period_id',
        'customer_id',
        'due_date',
        'status',
        'mode',
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
            'customer_id' => 'integer',
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

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /** @return HasMany<SalesInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class);
    }

    /** @return HasMany<CustomerPaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
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
