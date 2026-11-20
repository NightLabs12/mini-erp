<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Penjaga config/master_usage.php.
 *
 * Tabel dokumen yang belum ada harus terdaftar eksplisit di $pending. Saat
 * migrasi fase berikutnya membuat tabel tersebut, test ini gagal sampai
 * entri pending dihapus. Dengan begitu daftar referensi tidak pernah diam-diam
 * menunjuk tabel atau kolom yang salah.
 */
it('menyebut tabel dan kolom yang valid untuk setiap referensi master', function (): void {
    /** @var array<class-string, list<array{0: string, 1: string}>> $references */
    $references = config('master_usage.references', []);

    expect($references)->toBeArray()->not->toBeEmpty();

    foreach ($references as $model => $refs) {
        expect(class_exists($model))->toBeTrue();

        foreach ($refs as $reference) {
            expect($reference)->toHaveCount(2);

            [$table, $column] = $reference;

            if (! Schema::hasTable($table)) {
                continue;
            }

            expect(Schema::hasColumn($table, $column))->toBeTrue();
        }
    }
});

it('menandai tabel referensi yang belum ada sebagai pending secara eksplisit', function (): void {
    $pending = [
        'sales_order_items',
        'delivery_order_items',
        'sales_orders',
        'delivery_orders',
        'sales_invoices',
    ];

    /** @var array<class-string, list<array{0: string, 1: string}>> $references */
    $references = config('master_usage.references', []);

    $referencedTables = [];

    foreach ($references as $refs) {
        foreach ($refs as [$table]) {
            $referencedTables[] = $table;
        }
    }

    $referencedTables = array_values(array_unique($referencedTables));
    $missing = array_values(array_filter(
        $referencedTables,
        static fn (string $table): bool => ! Schema::hasTable($table),
    ));

    sort($missing);
    sort($pending);

    expect($missing)->toBe($pending);

    foreach ($pending as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

it('mendaftarkan document_tables yang semuanya sudah menjadi referensi', function (): void {
    /** @var array<class-string, list<array{0: string, 1: string}>> $references */
    $references = config('master_usage.references', []);
    /** @var list<string> $documents */
    $documents = config('master_usage.document_tables', []);

    $referencedTables = [];

    foreach ($references as $refs) {
        foreach ($refs as [$table]) {
            $referencedTables[] = $table;
        }
    }

    expect(array_values(array_diff($documents, array_unique($referencedTables))))->toBe([]);
});
