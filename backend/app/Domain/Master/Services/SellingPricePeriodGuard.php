<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Master\Models\SellingPrice;
use App\Exceptions\ApiException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

/**
 * Menjaga satu kombinasi (item, satuan, customer) hanya punya satu rentang
 * harga yang berlaku pada satu tanggal.
 *
 * Batas tanggal inklusif: valid_to 30 Juni dan valid_from 30 Juni dianggap
 * tumpang tindih. valid_from/valid_to null berarti terbuka tanpa batas.
 */
final class SellingPricePeriodGuard
{
    public function assertNoOverlap(
        int $itemId,
        int $unitId,
        ?int $customerId,
        CarbonInterface|string|null $validFrom,
        CarbonInterface|string|null $validTo,
        ?int $ignoreId = null,
    ): void {
        $from = $this->dateString($validFrom);
        $to = $this->dateString($validTo);

        $query = SellingPrice::query()
            ->where('item_id', $itemId)
            ->where('unit_id', $unitId);

        if ($customerId === null) {
            $query->whereNull('customer_id');
        } else {
            $query->where('customer_id', $customerId);
        }

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($to !== null) {
            $query->where(static function (Builder $builder) use ($to): void {
                $builder->whereNull('valid_from')->orWhereDate('valid_from', '<=', $to);
            });
        }

        if ($from !== null) {
            $query->where(static function (Builder $builder) use ($from): void {
                $builder->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from);
            });
        }

        if (! $query->exists()) {
            return;
        }

        throw $this->overlapError();
    }

    public function isOverlapViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return $sqlState === '23P01'
            || str_contains($exception->getMessage(), 'selling_prices_period_no_overlap');
    }

    public function overlapError(): ApiException
    {
        return ApiException::unprocessable(
            'Rentang harga tumpang tindih dengan harga lain untuk item, satuan, dan customer yang sama.',
            'PRICE_PERIOD_OVERLAP',
            ['valid_from' => ['Rentang tanggal bertabrakan dengan harga yang sudah ada.']],
        );
    }

    private function dateString(CarbonInterface|string|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date instanceof CarbonInterface ? $date->toDateString() : $date;
    }
}
