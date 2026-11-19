<?php

declare(strict_types=1);

use App\Domain\Core\Services\SettingService;

it('menyediakan nilai default walau tabel settings belum diisi', function (): void {
    $service = app(SettingService::class);

    expect($service->get('tax_rate'))->toBe('11')
        ->and($service->bool('allow_negative_stock'))->toBeFalse()
        ->and($service->bool('require_no_draft_on_close'))->toBeTrue()
        ->and($service->int('tax_rate'))->toBe(11)
        ->and($service->decimal('tax_rate'))->toBe('11')
        ->and($service->get('tidak_ada', 'cadangan'))->toBe('cadangan');
});

it('menyimpan dan membaca kembali nilai setting', function (): void {
    $service = app(SettingService::class);

    $service->set('tax_rate', '12');
    $service->set('allow_negative_stock', 'true');

    $fresh = new SettingService;

    expect($fresh->get('tax_rate'))->toBe('12')
        ->and($fresh->bool('allow_negative_stock'))->toBeTrue()
        ->and($fresh->int('tax_rate'))->toBe(12);
});
