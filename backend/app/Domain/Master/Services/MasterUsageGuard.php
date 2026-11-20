<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menolak penghapusan atau perubahan struktural master yang masih
 * direferensikan data lain.
 *
 * Daftar referensi ada di config/master_usage.php. Tabel yang belum ada
 * (misalnya dokumen pada fase berikutnya) dilewati, sehingga menambah tabel
 * baru cukup dengan menambah entri konfigurasi. Begitu tabel dokumen dibuat,
 * pemeriksaan ITEM_IN_USE otomatis aktif tanpa perubahan kode.
 */
final class MasterUsageGuard
{
    public function assertUnused(Model $model): void
    {
        $usedBy = $this->usedBy($model);

        if ($usedBy === []) {
            return;
        }

        throw ApiException::conflict(
            'Data masih dipakai oleh: '.implode(', ', $usedBy).'. Hapus atau ubah referensinya lebih dulu.',
            'MASTER_IN_USE',
            ['references' => $usedBy],
        );
    }

    /**
     * Menolak perubahan yang mengubah arti data historis, misalnya satuan base
     * atau faktor konversi item yang sudah muncul di dokumen.
     */
    public function assertNotUsedByDocuments(Model $model, string $message, string $code = 'ITEM_IN_USE'): void
    {
        $usedBy = $this->usedByDocuments($model);

        if ($usedBy === []) {
            return;
        }

        throw ApiException::conflict($message, $code, ['references' => $usedBy]);
    }

    /**
     * Tabel yang masih mereferensikan model dan sudah ada di database.
     *
     * @return list<string>
     */
    public function usedBy(Model $model): array
    {
        $tables = [];

        foreach ($this->referencesFor($model) as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $query = DB::table($table)->where($column, $model->getKey());

            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            if ($query->exists()) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return list<string>
     */
    public function usedByDocuments(Model $model): array
    {
        /** @var array<string, true> $documents */
        $documents = array_fill_keys(
            array_map('strval', (array) config('master_usage.document_tables', [])),
            true,
        );

        return array_values(array_filter(
            $this->usedBy($model),
            static fn (string $table): bool => isset($documents[$table]),
        ));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function referencesFor(Model $model): array
    {
        /** @var array<class-string, list<array{0: string, 1: string}>> $map */
        $map = config('master_usage.references', []);

        return $map[$model::class] ?? [];
    }
}
