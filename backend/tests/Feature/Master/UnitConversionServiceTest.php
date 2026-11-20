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
        ->and($this->service->toBase($this->item, $this->pcs, '5'))->toBe('5.0000');
});

it('menolak qty pecahan pada satuan base bulat', function (): void {
    try {
        $this->service->toBase($this->item, $this->pcs, '0.5');
        $this->fail('Qty 0,5 PCS seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('QTY_PRECISION_INVALID')
            ->and($exception->status())->toBe(422);
    }
});

it('menerima qty pecahan satuan turunan bila hasil konversinya bulat', function (): void {
    // 0,5 Dus x 144 Pcs = 72 Pcs, sehingga masih dapat disimpan pada satuan bulat.
    expect($this->service->toBase($this->item, $this->dus, '0.5'))->toBe('72.0000');
});

it('menolak qty pecahan satuan turunan bila hasil konversinya pecahan', function (): void {
    $dus145 = masterUnit('DUS145', 'Dus 145');

    $item = masterItem($this->pcs, ['sku' => 'KAOS-145', 'name' => 'Kaos 145']);
    addConversion($item, $dus145, '145');

    try {
        $this->service->toBase($item, $dus145, '0.5');
        $this->fail('0,5 x 145 = 72,5 PCS seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('QTY_PRECISION_INVALID')
            ->and($exception->status())->toBe(422);
    }
});

it('menerima qty pecahan pada satuan base yang memang berdesimal', function (): void {
    $meter = masterUnit('METER', 'Meter', 3);
    $kain = masterItem($meter, ['sku' => 'KAIN-MTR', 'name' => 'Kain Meter']);

    expect($this->service->toBase($kain, $meter, '1.25'))->toBe('1.2500');
});

it('menolak qty base yang melebihi presisi satuan base berdesimal', function (): void {
    $meter = masterUnit('METER', 'Meter', 3);
    $roll = masterUnit('ROLL', 'Roll', 4);
    $kain = masterItem($meter, ['sku' => 'KAIN-ROLL', 'name' => 'Kain Roll']);
    addConversion($kain, $roll, '3');

    // 0,3333 Roll x 3 Meter = 0,9999 Meter, melebihi 3 desimal.
    expect(fn () => $this->service->toBase($kain, $roll, '0.3333'))
        ->toThrow(ApiException::class);
});

it('membulatkan qty base ke empat desimal', function (): void {
    $meter = masterUnit('METER', 'Meter', 4);
    $roll = masterUnit('ROLL', 'Roll', 4);

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

it('mengembalikan harga satuan input dari biaya per base dengan dua desimal', function (): void {
    expect($this->service->unitPriceFromBase($this->item, $this->dus, '11000'))->toBe('1584000.00')
        ->and($this->service->unitPriceFromBase($this->item, $this->pack, '11000'))->toBe('132000.00')
        ->and($this->service->unitPriceFromBase($this->item, $this->pcs, '10000.005'))->toBe('10000.01');
});

it('menolak satuan yang belum didefinisikan untuk item', function (): void {
    $kg = masterUnit('KG', 'Kilogram', 3);

    expect(fn () => $this->service->toBase($this->item, $kg, '1'))
        ->toThrow(ApiException::class);

    try {
        $this->service->toBase($this->item, $kg, '1');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('UNIT_NOT_CONVERTIBLE')
            ->and($exception->status())->toBe(422);
    }
});
