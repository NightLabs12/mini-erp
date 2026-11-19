<?php

declare(strict_types=1);

use App\Domain\Core\Services\DocumentSequenceService;
use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;

it('memformat nomor sesuai {PREFIX}/{YYYY}/{MM}/{0000}', function (): void {
    $service = app(DocumentSequenceService::class);

    $number = $service->next('PO', CarbonImmutable::parse('2026-03-05'));

    expect($number)->toBe('PO/2026/03/0001');
});

it('menaikkan nomor per jenis, tahun, dan bulan', function (): void {
    $service = app(DocumentSequenceService::class);

    expect($service->next('GR', CarbonImmutable::parse('2026-03-05')))->toBe('GR/2026/03/0001')
        ->and($service->next('GR', CarbonImmutable::parse('2026-03-20')))->toBe('GR/2026/03/0002')
        ->and($service->next('GR', CarbonImmutable::parse('2026-04-01')))->toBe('GR/2026/04/0001')
        ->and($service->next('SI', CarbonImmutable::parse('2026-03-05')))->toBe('SI/2026/03/0001')
        ->and($service->next('GR', CarbonImmutable::parse('2027-03-05')))->toBe('GR/2027/03/0001');
});

it('menolak jenis dokumen yang tidak terdaftar', function (): void {
    expect(fn () => app(DocumentSequenceService::class)->next('XXX'))
        ->toThrow(ApiException::class);
});
