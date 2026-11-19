<?php

declare(strict_types=1);

use App\Exceptions\ApiException;

it('membawa kode mesin dan status untuk konflik bisnis', function (): void {
    $exception = ApiException::conflict('Stok tidak cukup.', 'STOCK_INSUFFICIENT');

    expect($exception->status())->toBe(409)
        ->and($exception->errorCode())->toBe('STOCK_INSUFFICIENT')
        ->and($exception->getMessage())->toBe('Stok tidak cukup.')
        ->and($exception->errors())->toBe([]);
});

it('memakai status 422 untuk kesalahan yang dapat diperbaiki pengirim', function (): void {
    $exception = ApiException::unprocessable('Periode sudah ditutup.', 'PERIOD_CLOSED', [
        'doc_date' => ['Tanggal berada di periode closed.'],
    ]);

    expect($exception->status())->toBe(422)
        ->and($exception->errorCode())->toBe('PERIOD_CLOSED')
        ->and($exception->errors())->toBe(['doc_date' => ['Tanggal berada di periode closed.']]);
});

it('memberi pesan bawaan pada notFound', function (): void {
    $exception = ApiException::notFound();

    expect($exception->status())->toBe(404)
        ->and($exception->errorCode())->toBe('NOT_FOUND')
        ->and($exception->getMessage())->toBe('Data tidak ditemukan.');
});
