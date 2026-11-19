<?php

declare(strict_types=1);

use App\Support\Math\Decimal;

it('membulatkan setengah ke atas pada skala uang', function (): void {
    expect(Decimal::round('1234.565', 2))->toBe('1234.57')
        ->and(Decimal::round('1234.564', 2))->toBe('1234.56')
        ->and(Decimal::round('0.005', 2))->toBe('0.01')
        ->and(Decimal::round('0.004', 2))->toBe('0.00');
});

it('membulatkan nilai negatif menjauhi nol', function (): void {
    expect(Decimal::round('-1234.565', 2))->toBe('-1234.57')
        ->and(Decimal::round('-0.005', 2))->toBe('-0.01')
        // Nol tidak punya tanda, sehingga hasilnya "0.00" bukan "-0.00".
        ->and(Decimal::round('-0.004', 2))->toBe('0.00');
});

it('menjaga presisi pada perkalian dan pembagian', function (): void {
    expect(Decimal::mul('1440', '10000', 4))->toBe('14400000.0000')
        ->and(Decimal::div('1440000', '144', 4))->toBe('10000.0000')
        ->and(Decimal::div('100', '3', 4))->toBe('33.3333')
        ->and(Decimal::add('0.1', '0.2', 4))->toBe('0.3000');
});

it('menolak pembagian dengan nol', function (): void {
    expect(fn () => Decimal::div('10', '0'))->toThrow(InvalidArgumentException::class);
});

it('menolak nilai yang bukan angka', function (): void {
    expect(fn () => Decimal::add('10', 'abc'))->toThrow(InvalidArgumentException::class);
});

it('membandingkan dan mengambil nilai absolut', function (): void {
    expect(Decimal::compare('10.5', '10.50'))->toBe(0)
        ->and(Decimal::compare('10.51', '10.50'))->toBe(1)
        ->and(Decimal::isNegative('-0.0001'))->toBeTrue()
        ->and(Decimal::abs('-7.25'))->toBe('7.2500');
});
