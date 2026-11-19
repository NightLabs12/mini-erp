<?php

declare(strict_types=1);

use App\Domain\Master\Services\UnitConversionService;
use App\Exceptions\ApiException;

/**
 * Skenario acuan SPEC: Kaos Polos base Pcs, Pack = 12 Pcs, Dus = 144 Pcs.
 */
beforeEach(function (): void {
    $this->pcs = masterUnit('PCS', 'Pcs');
    $this->pack = masterUnit('PACK', 'Pack');
    $this->dus = masterUnit('DUS', 'Dus');

    $this->item = masterItem($this->pcs, ['sku' => 'KAOS-POLOS', 'name' => 'Kaos Polos']);
    addConversion($this->item, $this->pack, '12');
    addConversion($this->item, $this->dus, '144');

    $this->service = app(UnitConversionService::class);
});

it('mengonversi Dus dan Pack ke satuan base', function (): void {
    expect($this->service->toBase($this->item, $this->dus, '10'))->toBe('1440.0000')
        ->and($this->service->toBase($this->item, $this->pack, '2'))->toBe('24.0000')
        ->and($this->service->toBase($this->item, $this->dus, '1'))->toBe('144.0000');
});

it('memperlakukan satuan base dengan faktor satu', function (): void {
    expect($this->service->factorToBase($this->item, $this->pcs))->toBe('1.0000')
        ->and($this->service->toBase($this->item, $this->pcs, '5'))->toBe('5.0000')
        ->and($this->service->toBase($this->item, $this->pcs, '0.5'))->toBe('0.5000');
});

it('membulatkan qty base ke empat desimal', function (): void {
    $meter = masterUnit('METER', 'Meter');
    $roll = masterUnit('ROLL', 'Roll');

    $kain = masterItem($meter, ['sku' => 'KAIN-1', 'name' => 'Kain Katun']);
    addConversion($kain, $roll, '3');

    // 0.3333 Roll x 3 Meter = 0.9999 Meter.
    expect($this->service->toBase($kain, $roll, '0.3333'))->toBe('0.9999');
});

it('menghitung biaya per satuan base dari harga satuan input', function (): void {
    // Rp1.440.000 per Dus (144 Pcs) = Rp10.000 per Pcs.
    expect($this->service->unitCostPerBase($this->item, $this->dus, '1440000'))->toBe('10000.0000')
        ->and($this->service->unitCostPerBase($this->item, $this->pack, '180000'))->toBe('15000.0000')
        ->and($this->service->unitCostPerBase($this->item, $this->pcs, '15000'))->toBe('15000.0000');
});

it('mengembalikan harga satuan input dari biaya per base', function (): void {
    expect($this->service->unitPriceFromBase($this->item, $this->dus, '11000'))->toBe('1584000.0000')
        ->and($this->service->unitPriceFromBase($this->item, $this->pack, '11000'))->toBe('132000.0000');
});

it('menolak satuan yang belum didefinisikan untuk item', function (): void {
    $kg = masterUnit('KG', 'Kilogram');

    expect(fn () => $this->service->toBase($this->item, $kg, '1'))
        ->toThrow(ApiException::class);

    try {
        $this->service->toBase($this->item, $kg, '1');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('UNIT_NOT_CONVERTIBLE')
            ->and($exception->status())->toBe(422);
    }
});
