<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\Unit;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;

/**
 * Konversi satuan bertingkat.
 *
 * Dokumen menyimpan qty dalam satuan input dan qty_base hasil konversi.
 * Seluruh saldo stok dan unit_cost di ledger selalu dalam satuan base, jadi
 * setiap perhitungan stok wajib lewat service ini.
 */
final class UnitConversionService
{
    public const BASE_FACTOR = '1';

    public function factorToBase(Item $item, Unit $unit): string
    {
        if ((int) $item->base_unit_id === (int) $unit->getKey()) {
            return bcadd(self::BASE_FACTOR, '0', Decimal::QTY_SCALE);
        }

        $conversion = $item->conversions()
            ->where('unit_id', $unit->getKey())
            ->first();

        if ($conversion === null) {
            throw ApiException::unprocessable(
                "Satuan {$unit->name} belum didefinisikan untuk item {$item->name}.",
                'UNIT_NOT_CONVERTIBLE',
                ['unit_id' => ["Satuan {$unit->name} tidak tersedia untuk item {$item->sku}."]],
            );
        }

        return (string) $conversion->factor_to_base;
    }

    /**
     * Mengubah qty satuan input menjadi qty_base (satuan base), 4 desimal.
     */
    public function toBase(Item $item, Unit $unit, string|int $qty): string
    {
        $factor = $this->factorToBase($item, $unit);

        return Decimal::round(Decimal::mul($qty, $factor, Decimal::QTY_SCALE + 2), Decimal::QTY_SCALE);
    }

    /**
     * Biaya per satuan base dari harga per satuan input.
     *
     * Contoh: harga Rp1.440.000 per Dus (144 Pcs) menghasilkan Rp10.000 per Pcs.
     */
    public function unitCostPerBase(Item $item, Unit $unit, string|int $unitPrice): string
    {
        $factor = $this->factorToBase($item, $unit);

        return Decimal::div($unitPrice, $factor, Decimal::QTY_SCALE);
    }

    /**
     * Harga per satuan input dari biaya per satuan base.
     */
    public function unitPriceFromBase(Item $item, Unit $unit, string|int $baseCost): string
    {
        $factor = $this->factorToBase($item, $unit);

        return Decimal::round(Decimal::mul($baseCost, $factor, Decimal::QTY_SCALE + 2), Decimal::QTY_SCALE);
    }
}
