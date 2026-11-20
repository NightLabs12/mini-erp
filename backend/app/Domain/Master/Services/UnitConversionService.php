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
 *
 * Presisi mengikuti units.decimal_places. Satuan base yang bulat (PCS, DUS,
 * ROLL) tidak menerima qty_base pecahan. Satuan input yang sekaligus satuan
 * base juga tidak menerima qty lebih presisi dari kolomnya. Satuan turunan
 * seperti DUS boleh diketik pecahan selama hasil konversinya pas pada satuan
 * base, misalnya 0,5 DUS x 144 = 72 PCS.
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
     *
     * @throws ApiException bila presisi qty melebihi satuan atau qty_base
     *                      tidak dapat dinyatakan pada satuan base.
     */
    public function toBase(Item $item, Unit $unit, string|int $qty): string
    {
        $inputDecimals = $this->decimalPlaces($qty);

        if ($inputDecimals > Decimal::QTY_SCALE) {
            throw $this->precisionError($unit, $qty, Decimal::QTY_SCALE);
        }

        $baseUnit = $this->baseUnit($item);
        $isBaseUnit = (int) $baseUnit->getKey() === (int) $unit->getKey();

        // Satuan base adalah acuan stok: qty yang diketik langsung pada satuan
        // ini harus muat pada presisinya.
        if ($isBaseUnit && $inputDecimals > (int) $unit->decimal_places) {
            throw $this->precisionError($unit, $qty, (int) $unit->decimal_places);
        }

        $factor = $this->factorToBase($item, $unit);
        $qtyBase = Decimal::round(
            Decimal::mul($qty, $factor, Decimal::QTY_SCALE + 2),
            Decimal::QTY_SCALE,
        );

        if ($this->decimalPlaces($qtyBase) > (int) $baseUnit->decimal_places) {
            throw ApiException::unprocessable(
                "Qty {$qty} {$unit->code} menghasilkan {$qtyBase} {$baseUnit->code}, "
                ."melebihi {$baseUnit->decimal_places} desimal pada satuan base.",
                'QTY_PRECISION_INVALID',
                ['qty' => ["Hasil konversi tidak dapat dinyatakan dalam {$baseUnit->code}."]],
            );
        }

        return $qtyBase;
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
     *
     * Nilainya uang, sehingga selalu dibulatkan 2 desimal HALF_UP.
     */
    public function unitPriceFromBase(Item $item, Unit $unit, string|int $baseCost): string
    {
        $factor = $this->factorToBase($item, $unit);

        return Decimal::round(
            Decimal::mul($baseCost, $factor, Decimal::QTY_SCALE + 2),
            Decimal::MONEY_SCALE,
        );
    }

    private function baseUnit(Item $item): Unit
    {
        $unit = $item->relationLoaded('baseUnit')
            ? $item->getRelation('baseUnit')
            : $item->baseUnit()->first();

        if (! $unit instanceof Unit) {
            throw ApiException::unprocessable(
                "Satuan base item {$item->sku} tidak ditemukan.",
                'UNIT_NOT_CONVERTIBLE',
            );
        }

        return $unit;
    }

    /**
     * Jumlah desimal bermakna; nol di belakang koma tidak dihitung.
     */
    private function decimalPlaces(string|int $value): int
    {
        $number = is_int($value) ? (string) $value : trim($value);

        if ($number === '' || preg_match('/^-?\d+(\.\d+)?$/', $number) !== 1) {
            throw ApiException::unprocessable(
                "Qty tidak valid: {$value}.",
                'QTY_PRECISION_INVALID',
            );
        }

        $dot = strpos($number, '.');

        if ($dot === false) {
            return 0;
        }

        return strlen(rtrim(substr($number, $dot + 1), '0'));
    }

    private function precisionError(Unit $unit, string|int $qty, int $allowed): ApiException
    {
        return ApiException::unprocessable(
            "Qty {$qty} melebihi {$allowed} desimal untuk satuan {$unit->code}.",
            'QTY_PRECISION_INVALID',
            ['qty' => ["Satuan {$unit->code} hanya menerima {$allowed} desimal."]],
        );
    }
}
