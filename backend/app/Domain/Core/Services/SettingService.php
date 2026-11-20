<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Akses setting bertipe dengan nilai default yang aman.
 *
 * Nilai default didefinisikan di kode, bukan hanya di seeder, sehingga test
 * dan instalasi baru berperilaku sama walau tabel settings belum diisi.
 */
final class SettingService
{
    /**
     * @var array<string, array{value: string, type: string, group: string, description: string}>
     */
    public const DEFAULTS = [
        'company_name' => [
            'value' => 'PT Contoh Niaga Makmur',
            'type' => 'string',
            'group' => 'general',
            'description' => 'Nama perusahaan pada dokumen dan laporan.',
        ],
        'currency' => [
            'value' => 'IDR',
            'type' => 'string',
            'group' => 'general',
            'description' => 'Mata uang tunggal sistem.',
        ],
        'tax_rate' => [
            'value' => '11',
            'type' => 'decimal',
            'group' => 'tax',
            'description' => 'Tarif PPN default dalam persen.',
        ],
        'allow_negative_stock' => [
            'value' => 'false',
            'type' => 'boolean',
            'group' => 'inventory',
            'description' => 'Izinkan stok keluar melebihi saldo tersedia.',
        ],
        'gr_over_receipt_tolerance_pct' => [
            'value' => '0',
            'type' => 'decimal',
            'group' => 'inventory',
            'description' => 'Toleransi persen qty goods receipt melebihi sisa purchase order.',
        ],
        'credit_limit_enforcement' => [
            'value' => 'warn',
            'type' => 'string',
            'group' => 'sales',
            'description' => 'Perilaku cek credit limit: off, warn, atau block.',
        ],
        'require_no_draft_on_close' => [
            'value' => 'true',
            'type' => 'boolean',
            'group' => 'accounting',
            'description' => 'Tolak tutup periode bila masih ada dokumen draft pada periode itu.',
        ],
        'document_number_format' => [
            'value' => '{PREFIX}/{YYYY}/{MM}/{0000}',
            'type' => 'string',
            'group' => 'document',
            'description' => 'Format nomor dokumen.',
        ],
    ];

    /** @var array<string, string|null>|null */
    private ?array $values = null;

    /** @return array<string, string|null> */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $stored = Setting::query()->pluck('value', 'key')->all();

        $values = [];

        foreach (self::DEFAULTS as $key => $definition) {
            $values[$key] = $stored[$key] ?? $definition['value'];
        }

        foreach ($stored as $key => $value) {
            if (! array_key_exists($key, $values)) {
                $values[$key] = $value;
            }
        }

        return $this->values = $values;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null || ! is_numeric($value) ? $default : (int) $value;
    }

    public function decimal(string $key, string $default = '0'): string
    {
        $value = $this->get($key);

        return $value === null || ! is_numeric($value) ? $default : $value;
    }

    public function set(string $key, string $value): void
    {
        $definition = self::DEFAULTS[$key] ?? [
            'type' => 'string',
            'group' => 'general',
            'description' => null,
        ];

        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => $value,
                'type' => $definition['type'],
                'group' => $definition['group'],
                'description' => $definition['description'],
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $this->values = null;
    }

    public function forgetCached(): void
    {
        $this->values = null;
    }
}
