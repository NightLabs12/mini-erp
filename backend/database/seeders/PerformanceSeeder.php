<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data performa untuk uji laporan. Tidak dipanggil DatabaseSeeder agar
 * `make demo` tetap cepat.
 *
 * Default 10.000 jurnal x 2 baris = 20.000 baris jurnal. Jalankan manual:
 *   php artisan db:seed --class=PerformanceSeeder
 *
 * Jumlah jurnal dapat diubah lewat env PERF_JOURNAL_COUNT.
 */
class PerformanceSeeder extends Seeder
{
    public function run(): void
    {
        if (Account::query()->count() === 0) {
            $this->call(AccountingSeeder::class);
        }

        $count = max(1, (int) (getenv('PERF_JOURNAL_COUNT') ?: 10000));
        $date = CarbonImmutable::now()->startOfMonth()->addDays(9);
        $period = AccountingPeriod::query()->firstOrCreate(
            ['year' => $date->year, 'month' => $date->month],
            ['status' => AccountingPeriod::STATUS_OPEN],
        );

        /** @var Account $debit */
        $debit = Account::query()->where('code', '1-1100')->firstOrFail();
        /** @var Account $credit */
        $credit = Account::query()->where('code', '4-1000')->firstOrFail();

        $this->command?->getOutput()->writeln("Membuat {$count} jurnal performa...");

        DB::transaction(function () use ($count, $date, $period, $debit, $credit): void {
            $now = now();

            for ($offset = 0; $offset < $count; $offset += 1000) {
                $journals = [];
                $lines = [];
                $batch = min(1000, $count - $offset);

                for ($index = 0; $index < $batch; $index++) {
                    $number = $offset + $index + 1;
                    $journalId = $offset + $index + 1;
                    $day = $date->addDays($number % 20)->toDateString();

                    $journals[] = [
                        'id' => $journalId,
                        'journal_no' => sprintf('JV/PERF/%06d', $number),
                        'journal_date' => $day,
                        'period_id' => $period->getKey(),
                        'source_type' => 'performance',
                        'source_id' => $number,
                        'description' => "Jurnal performa #{$number}",
                        'status' => 'draft',
                        'total_debit' => '1000.00',
                        'total_credit' => '1000.00',
                        'created_by' => null,
                        'approved_by' => null,
                        'approved_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $lines[] = [
                        'journal_id' => $journalId,
                        'account_id' => $debit->getKey(),
                        'journal_date' => $day,
                        'line_no' => 1,
                        'debit' => '1000.00',
                        'credit' => '0.00',
                        'description' => 'Kas',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $lines[] = [
                        'journal_id' => $journalId,
                        'account_id' => $credit->getKey(),
                        'journal_date' => $day,
                        'line_no' => 2,
                        'debit' => '0.00',
                        'credit' => '1000.00',
                        'description' => 'Penjualan',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('journals')->insert($journals);
                DB::table('journal_lines')->insert($lines);
            }

            DB::table('journals')
                ->where('source_type', 'performance')
                ->update(['status' => 'posted', 'updated_at' => $now]);

            DB::statement("SELECT setval(pg_get_serial_sequence('journals', 'id'), (SELECT MAX(id) FROM journals))");
            DB::statement("SELECT setval(pg_get_serial_sequence('journal_lines', 'id'), (SELECT MAX(id) FROM journal_lines))");
        }, 5);

        $this->command?->getOutput()->writeln('Selesai: '.(2 * $count).' baris jurnal.');
    }
}
