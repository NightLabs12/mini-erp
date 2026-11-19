<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $doc_type
 * @property int $year
 * @property int $month
 * @property int $last_number
 */
class DocumentSequence extends Model
{
    protected $fillable = [
        'doc_type',
        'year',
        'month',
        'last_number',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
