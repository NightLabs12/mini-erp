<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

it('memformat endpoint yang tidak ada sebagai NOT_FOUND', function (): void {
    $this->getJson('/api/v1/tidak-ada')
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertJsonPath('message', 'Endpoint tidak ditemukan.')
        ->assertJsonPath('errors', []);
});

it('memformat metode HTTP yang salah sebagai METHOD_NOT_ALLOWED', function (): void {
    $this->postJson('/api/v1/health')
        ->assertStatus(405)
        ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
});

it('memakai kode bisnis dari ApiException', function (): void {
    Route::middleware('api')->get('/api/v1/_test/stock', function (): never {
        throw ApiException::conflict('Stok Kaos Polos di Gudang Utama tidak cukup.', 'STOCK_INSUFFICIENT');
    });

    $this->getJson('/api/v1/_test/stock')
        ->assertStatus(409)
        ->assertJsonPath('code', 'STOCK_INSUFFICIENT')
        ->assertJsonPath('message', 'Stok Kaos Polos di Gudang Utama tidak cukup.');
});

it('memformat error validasi dengan daftar field', function (): void {
    Route::middleware('api')->post('/api/v1/_test/validasi', function (): never {
        throw ValidationException::withMessages([
            'email' => ['Email wajib diisi.'],
            'password' => ['Password minimal 8 karakter.'],
        ]);
    });

    $this->postJson('/api/v1/_test/validasi')
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('message', 'Data yang dikirim tidak valid.')
        ->assertJsonPath('errors.email.0', 'Email wajib diisi.')
        ->assertJsonPath('errors.password.0', 'Password minimal 8 karakter.');
});
