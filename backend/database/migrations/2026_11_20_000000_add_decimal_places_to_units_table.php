<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presisi kuantitas per satuan.
 *
 * PCS/PACK/DUS/ROLL hanya boleh bilangan bulat, sedangkan METER dan KG
 * menerima pecahan. Nilai ini dipakai UnitConversionService untuk menolak qty
 * yang lebih presisi dari satuannya dan qty_base pecahan pada satuan base bulat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->smallInteger('decimal_places')->default(0)->after('name');
        });

        DB::statement('ALTER TABLE units ADD CONSTRAINT units_decimal_places_range CHECK (decimal_places BETWEEN 0 AND 4)');

        DB::table('units')->whereIn('code', ['METER', 'KG'])->update(['decimal_places' => 3]);
        DB::table('units')->whereIn('code', ['PCS', 'PACK', 'DUS', 'ROLL'])->update(['decimal_places' => 0]);
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->dropColumn('decimal_places');
        });
    }
};
