<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Dua pengeluaran bersamaan pada stok terbatas: hanya satu yang boleh
 * berhasil, dan saldo akhir tidak boleh negatif (SPEC 9 nomor 16).
 *
 * Data disiapkan lewat koneksi terpisah yang commit, karena proses anak tidak
 * dapat melihat data di dalam transaksi RefreshDatabase milik test induk.
 */
it('menolak salah satu pengeluaran saat stok terbatas', function (): void {
    config(['database.connections.inventory_probe' => config('database.connections.pgsql')]);
    DB::purge('inventory_probe');

    /** @var Illuminate\Database\Connection $probe */
    $probe = DB::connection('inventory_probe');

    $cleanup = static function () use ($probe): void {
        $probe->statement(
            'TRUNCATE stock_ledger, stock_balances, item_unit_conversions, selling_prices, '
            .'items, item_categories, units, warehouses RESTART IDENTITY CASCADE'
        );
    };

    $cleanup();

    $now = now();
    $unitId = $probe->table('units')->insertGetId([
        'code' => 'PCS',
        'name' => 'Pcs',
        'decimal_places' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $categoryId = $probe->table('item_categories')->insertGetId([
        'code' => 'CONC',
        'name' => 'Konkurensi',
        'item_type' => 'trading',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $itemId = $probe->table('items')->insertGetId([
        'sku' => 'CONC-1',
        'name' => 'Item Konkurensi',
        'category_id' => $categoryId,
        'base_unit_id' => $unitId,
        'min_stock' => '0.0000',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $warehouseId = $probe->table('warehouses')->insertGetId([
        'code' => 'GD-CONC',
        'name' => 'Gudang Konkurensi',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $probe->table('stock_balances')->insert([
        'item_id' => $itemId,
        'warehouse_id' => $warehouseId,
        'qty_base' => '10.0000',
        'avg_cost' => '1000.0000',
        'value' => '10000.00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $probe->table('stock_ledger')->insert([
        'item_id' => $itemId,
        'warehouse_id' => $warehouseId,
        'movement_date' => now()->toDateString(),
        'doc_type' => 'ADJ',
        'doc_id' => null,
        'doc_line_id' => null,
        'qty_in_base' => '10.0000',
        'qty_out_base' => '0.0000',
        'unit_cost' => '1000.0000',
        'total_value' => '10000.00',
        'balance_qty' => '10.0000',
        'balance_value' => '10000.00',
        'avg_cost_after' => '1000.0000',
        'reversal_of_id' => null,
        'created_by' => null,
        'created_at' => $now,
    ]);

    try {
        $worker = base_path('tests/Support/stock-issue-worker.php');
        $environment = stockIssueWorkerEnvironment();
        $date = now()->toDateString();
        $processes = [];

        for ($index = 1; $index <= 2; $index++) {
            $process = new Process(
                [PHP_BINARY, $worker, (string) $itemId, (string) $warehouseId, '8', (string) $index, $date],
                base_path(),
                $environment,
            );
            $process->setTimeout(60);
            $process->start();

            $processes[] = $process;
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();

            $results[] = [
                'ok' => $process->isSuccessful(),
                'out' => trim($process->getOutput()),
                'err' => trim($process->getErrorOutput()),
            ];
        }

        $succeeded = array_values(array_filter($results, static fn (array $result): bool => $result['ok']));
        $failed = array_values(array_filter($results, static fn (array $result): bool => ! $result['ok']));

        expect($succeeded)->toHaveCount(1)
            ->and($failed)->toHaveCount(1)
            ->and($failed[0]['err'])->toContain('STOCK_INSUFFICIENT');

        $balance = $probe->table('stock_balances')
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        expect((string) $balance->qty_base)->toBe('2.0000')
            ->and((string) $balance->value)->toBe('2000.00');

        $outbound = $probe->table('stock_ledger')
            ->where('item_id', $itemId)
            ->where('qty_out_base', '>', 0)
            ->get();

        expect($outbound)->toHaveCount(1)
            ->and((string) $outbound[0]->qty_out_base)->toBe('8.0000');
    } finally {
        $cleanup();
        DB::purge('inventory_probe');
    }
});

/**
 * Proses anak tidak mewarisi variabel phpunit, jadi koneksi database test
 * harus dikirim eksplisit.
 *
 * @return array<string, string>
 */
function stockIssueWorkerEnvironment(): array
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
