<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Core\Services\SettingService;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(SettingService::class);

        foreach (SettingService::DEFAULTS as $key => $definition) {
            $service->set($key, $definition['value']);
        }
    }
}
