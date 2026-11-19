<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menolak penghapusan master yang masih direferensikan data lain.
 *
 * Daftar referensi ada di config/master_usage.php. Tabel yang belum ada
 * (misalnya dokumen pada fase berikutnya) dilewati, sehingga menambah tabel
 * baru cukup dengan menambah entri konfigurasi.
 */
final class MasterUsageGuard
{
    public function assertUnused(Model $model): void
    {
        $usedBy = [];

        foreach ($this->referencesFor($model) as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $query = DB::table($table)->where($column, $model->getKey());

            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            if ($query->exists()) {
                $usedBy[] = $table;
            }
        }

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
     * @return list<array{0: string, 1: string}>
     */
    private function referencesFor(Model $model): array
    {
        /** @var array<class-string, list<array{0: string, 1: string}>> $map */
        $map = config('master_usage', []);

        return $map[$model::class] ?? [];
    }
}
