<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Master\Models\SellingPrice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menentukan harga jual yang berlaku.
 *
 * Urutan prioritas:
 * 1. Harga khusus customer yang tanggalnya valid.
 * 2. Harga umum (customer_id null) yang tanggalnya valid.
 *
 * Bila ada beberapa baris valid, valid_from terbaru menang; baris tanpa
 * valid_from dianggap paling lama. valid_to null berarti berlaku selamanya.
 */
final class SellingPriceResolver
{
    public function find(
        int $itemId,
        ?int $customerId,
        CarbonInterface|string $date,
        ?int $unitId = null,
    ): ?SellingPrice {
        $on = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        $query = SellingPrice::query()
            ->where('item_id', $itemId)
            ->where(static function (Builder $builder) use ($on): void {
                $builder->whereNull('valid_from')->orWhereDate('valid_from', '<=', $on);
            })
            ->where(static function (Builder $builder) use ($on): void {
                $builder->whereNull('valid_to')->orWhereDate('valid_to', '>=', $on);
            });

        if ($unitId !== null) {
            $query->where('unit_id', $unitId);
        }

        if ($customerId === null) {
            $query->whereNull('customer_id');
        } else {
            $query->where(static function (Builder $builder) use ($customerId): void {
                $builder->where('customer_id', $customerId)->orWhereNull('customer_id');
            });
        }

        return $query
            // false < true di PostgreSQL, sehingga harga khusus customer tampil lebih dulu.
            ->orderByRaw('customer_id IS NULL')
            ->orderByRaw('valid_from IS NULL')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }
}
