<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $customer_payment_id
 * @property int $sales_invoice_id
 * @property string $amount
 */
class CustomerPaymentAllocation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'customer_payment_id',
        'sales_invoice_id',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_payment_id' => 'integer',
            'sales_invoice_id' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<CustomerPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    /** @return BelongsTo<SalesInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
