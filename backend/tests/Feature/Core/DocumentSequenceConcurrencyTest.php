<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Penomoran harus tetap unik dan berurutan walau banyak proses meminta nomor
 * pada saat yang sama. Setiap proses adalah aplikasi Laravel terpisah yang
 * menyentuh database yang sama, sehingga lockForUpdate benar-benar diuji.
 *
 * Jumlah proses dibaca dari SEQUENCE_CONCURRENCY: 50 di CI, lebih rendah di
 * VPS bersama. Assertion tetap menuntut jumlah nomor unik yang sama dengan
 * jumlah proses dan rentang yang berurutan tanpa celah.
 */
it('menghasilkan nomor unik dan berurutan dari proses paralel', function (): void {
    $type = 'PO';
    $date = '2099-12-15';
    $worker = base_path('tests/Support/sequence-worker.php');
    $workers = max(1, (int) (getenv('SEQUENCE_CONCURRENCY') ?: 50));

    expect($worker)->toBeFile();

    $environment = sequenceWorkerEnvironment();
    $processes = [];

    for ($index = 0; $index < $workers; $index++) {
        $process = new Process([PHP_BINARY, $worker, $type, $date], base_path(), $environment);
        $process->setTimeout(180);
        $process->start();

        $processes[] = $process;
    }

    $numbers = [];

    foreach ($processes as $process) {
        $process->wait();

        if (! $process->isSuccessful()) {
            $this->fail('Worker gagal: '.$process->getErrorOutput().$process->getOutput());
        }

        $numbers[] = trim($process->getOutput());
    }

    expect(array_unique($numbers))->toHaveCount($workers);

    foreach ($numbers as $number) {
        expect($number)->toMatch('/^PO\/2099\/12\/\d{4}$/');
    }

    $sequence = array_map(
        static fn (string $number): int => (int) substr($number, -4),
        $numbers,
    );

    sort($sequence);

    expect($sequence[0])->toBeGreaterThan(0)
        ->and($sequence[$workers - 1] - $sequence[0] + 1)->toBe($workers);
});

/**
 * Proses anak tidak mewarisi variabel phpunit, jadi koneksi database test
 * harus dikirim eksplisit.
 *
 * @return array<string, string>
 */
function sequenceWorkerEnvironment(): array
{
    $inherited = getenv();
    $inherited = is_array($inherited) ? $inherited : [];

    /** @var array<string, string> $environment */
    $environment = array_filter($inherited, static fn (mixed $value): bool => is_string($value));

    return array_merge($environment, [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'APP_DEBUG' => 'true',
        'DB_CONNECTION' => (string) config('database.default'),
        'DB_HOST' => (string) config('database.connections.pgsql.host'),
        'DB_PORT' => (string) config('database.connections.pgsql.port'),
        'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
        'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
        'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ]);
}
