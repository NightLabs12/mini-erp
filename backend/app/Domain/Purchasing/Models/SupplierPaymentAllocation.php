<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $supplier_payment_id
 * @property int $purchase_invoice_id
 * @property string $amount
 */
class SupplierPaymentAllocation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'supplier_payment_id',
        'purchase_invoice_id',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_payment_id' => 'integer',
            'purchase_invoice_id' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SupplierPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    /** @return BelongsTo<PurchaseInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }
}
