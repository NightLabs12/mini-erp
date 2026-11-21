<?php

declare(strict_types=1);

/**
 * Penjaga konfigurasi test.
 *
 * Variabel lingkungan container (DB_DATABASE=mini_erp, APP_ENV=local) dibaca
 * lebih dulu oleh dotenv melalui $_SERVER, sehingga phpunit.xml wajib memaksa
 * nilainya lewat <server> juga. Tanpa ini, RefreshDatabase akan menghapus
 * database demo, bukan database test.
 */
it('menggunakan database test dan environment testing', function (): void {
    expect(config('database.default'))->toBe('pgsql')
        ->and(config('database.connections.pgsql.database'))->toBe('mini_erp_test')
        ->and(app()->environment())->toBe('testing')
        ->and(config('app.demo_mode'))->toBeFalse();
});
