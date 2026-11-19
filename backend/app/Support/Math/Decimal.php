<?php

declare(strict_types=1);

namespace App\Support\Math;

use InvalidArgumentException;

/**
 * Aritmetika desimal berbasis bcmath.
 *
 * Seluruh nilai uang dan kuantitas di sistem ini melewati kelas ini; float
 * tidak pernah dipakai. Pembulatan memakai HALF_UP (menjauhi nol), sesuai
 * SPEC 3.1, dan dilakukan eksplisit di titik yang membutuhkannya.
 */
final class Decimal
{
    public const MONEY_SCALE = 2;

    public const QTY_SCALE = 4;

    /** Skala kerja internal agar pembagian tidak kehilangan presisi terlalu dini. */
    private const WORK_SCALE = 12;

    public static function add(string|int $a, string|int $b, int $scale = self::QTY_SCALE): string
    {
        return bcadd(self::stringify($a), self::stringify($b), $scale);
    }

    public static function sub(string|int $a, string|int $b, int $scale = self::QTY_SCALE): string
    {
        return bcsub(self::stringify($a), self::stringify($b), $scale);
    }

    public static function mul(string|int $a, string|int $b, int $scale = self::QTY_SCALE): string
    {
        return bcmul(self::stringify($a), self::stringify($b), $scale);
    }

    public static function div(string|int $a, string|int $b, int $scale = self::QTY_SCALE): string
    {
        if (bccomp(self::stringify($b), '0', self::WORK_SCALE) === 0) {
            throw new InvalidArgumentException('Pembagian dengan nol tidak diperbolehkan.');
        }

        return self::round(bcdiv(self::stringify($a), self::stringify($b), self::WORK_SCALE), $scale);
    }

    /**
     * Pembulatan HALF_UP menjauhi nol pada skala yang diminta.
     */
    public static function round(string|int $value, int $scale = self::MONEY_SCALE): string
    {
        $number = self::stringify($value);
        $factor = bcpow('10', (string) $scale, 0);
        $sign = bccomp($number, '0', self::WORK_SCALE) < 0 ? '-1' : '1';

        $shifted = bcmul($number, $factor, self::WORK_SCALE);
        $adjusted = bcadd($shifted, bcmul($sign, '0.5', self::WORK_SCALE), self::WORK_SCALE);

        // bcadd dengan skala 0 memotong ke arah nol, sehingga penambahan 0.5
        // tadi menghasilkan pembulatan setengah ke atas.
        $truncated = bcadd($adjusted, '0', 0);

        return bcdiv($truncated, $factor, $scale);
    }

    public static function compare(string|int $a, string|int $b, int $scale = self::QTY_SCALE): int
    {
        return bccomp(self::stringify($a), self::stringify($b), $scale);
    }

    public static function isNegative(string|int $value): bool
    {
        return bccomp(self::stringify($value), '0', self::WORK_SCALE) < 0;
    }

    public static function abs(string|int $value, int $scale = self::QTY_SCALE): string
    {
        $number = self::stringify($value);

        return bccomp($number, '0', self::WORK_SCALE) < 0 ? bcmul($number, '-1', $scale) : bcadd($number, '0', $scale);
    }

    /**
     * Menolak float agar tidak ada nilai biner yang menyelinap ke perhitungan uang.
     */
    private static function stringify(string|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || preg_match('/^-?\d+(\.\d+)?$/', $trimmed) !== 1) {
            throw new InvalidArgumentException("Nilai desimal tidak valid: {$value}");
        }

        return $trimmed;
    }
}
