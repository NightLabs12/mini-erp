<?php

declare(strict_types=1);

namespace App\Domain\Master\Models;

use App\Domain\Core\Traits\Auditable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $tax_id
 * @property int $payment_term_days
 * @property string $credit_limit
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'address',
        'phone',
        'email',
        'tax_id',
        'payment_term_days',
        'credit_limit',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_term_days' => 'integer',
        ];
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
