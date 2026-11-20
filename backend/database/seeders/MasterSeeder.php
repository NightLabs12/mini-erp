<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Unit;
use App\Domain\Master\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Master dasar yang dibutuhkan seluruh modul transaksi.
 *
 * Idempoten: memakai updateOrCreate berdasarkan kode, sehingga aman dipanggil
 * berulang saat pengembangan maupun lewat demo:reset.
 */
class MasterSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'PCS', 'name' => 'Pcs', 'decimal_places' => 0],
            ['code' => 'PACK', 'name' => 'Pack', 'decimal_places' => 0],
            ['code' => 'DUS', 'name' => 'Dus', 'decimal_places' => 0],
            ['code' => 'METER', 'name' => 'Meter', 'decimal_places' => 3],
            ['code' => 'ROLL', 'name' => 'Roll', 'decimal_places' => 0],
            ['code' => 'KG', 'name' => 'Kilogram', 'decimal_places' => 3],
        ];

        foreach ($units as $unit) {
            Unit::query()->updateOrCreate(
                ['code' => $unit['code']],
                ['name' => $unit['name'], 'decimal_places' => $unit['decimal_places']],
            );
        }

        $categories = [
            ['code' => 'KAOS', 'name' => 'Kaos Jadi', 'item_type' => 'finished_good'],
            ['code' => 'KAIN', 'name' => 'Kain Katun', 'item_type' => 'raw_material'],
            ['code' => 'BENANG', 'name' => 'Benang Jahit', 'item_type' => 'raw_material'],
            ['code' => 'LABEL', 'name' => 'Label dan Hangtag', 'item_type' => 'trading'],
            ['code' => 'KEMASAN', 'name' => 'Kemasan', 'item_type' => 'trading'],
            ['code' => 'AKSESORIS', 'name' => 'Aksesoris', 'item_type' => 'trading'],
        ];

        foreach ($categories as $category) {
            ItemCategory::query()->updateOrCreate(
                ['code' => $category['code']],
                ['name' => $category['name'], 'item_type' => $category['item_type']],
            );
        }

        $warehouses = [
            ['code' => 'GD-UTM', 'name' => 'Gudang Utama', 'address' => 'Jl. Industri Raya No. 12, Bandung'],
            ['code' => 'GD-CBG', 'name' => 'Gudang Cabang', 'address' => 'Jl. Ahmad Yani No. 45, Bandung'],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::query()->updateOrCreate(
                ['code' => $warehouse['code']],
                ['name' => $warehouse['name'], 'address' => $warehouse['address'], 'is_active' => true],
            );
        }
    }
}
