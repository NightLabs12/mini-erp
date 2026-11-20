<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jaring pengaman terakhir agar dua request paralel tidak dapat menyisipkan
 * rentang harga yang tumpang tindih. Validasi ramah pengguna tetap dilakukan
 * SellingPricePeriodGuard; constraint ini hanya menangkap balapan.
 *
 * daterange memakai batas inklusif dan otomatis terbuka bila salah satu
 * tanggal null. COALESCE(customer_id, 0) membuat harga umum (null) tetap
 * dibandingkan sebagai satu kelompok.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE selling_prices
            ADD CONSTRAINT selling_prices_period_no_overlap
            EXCLUDE USING gist (
                item_id WITH =,
                unit_id WITH =,
                (COALESCE(customer_id, 0)) WITH =,
                daterange(valid_from, valid_to, '[]') WITH &&
            )
            WHERE (deleted_at IS NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE selling_prices DROP CONSTRAINT IF EXISTS selling_prices_period_no_overlap');
    }
};
