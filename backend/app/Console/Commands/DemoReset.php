<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DemoReset extends Command
{
    protected $signature = 'demo:reset
                            {--force : Jalankan tanpa konfirmasi interaktif}';

    protected $description = 'Menghapus seluruh data dan membangun ulang dataset demo';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Seluruh data akan dihapus. Lanjutkan?')) {
            $this->warn('Dibatalkan.');

            return self::FAILURE;
        }

        $this->call('migrate:fresh', [
            '--seed' => true,
            '--force' => true,
        ]);

        $this->info('Dataset demo berhasil dibangun ulang.');

        return self::SUCCESS;
    }
}
