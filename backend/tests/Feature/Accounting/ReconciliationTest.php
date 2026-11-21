<?php

declare(strict_types=1);

use App\Domain\Accounting\Services\ReconciliationService;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);
    $this->reconciliation = app(ReconciliationService::class);
});

it('menghasilkan rekonsiliasi OK pada database tanpa dokumen', function (): void {
    $report = $this->reconciliation->report(now()->toDateString());

    expect($report['ok'])->toBeTrue()
        ->and($report['checks'])->toHaveCount(4);

    foreach ($report['checks'] as $check) {
        expect($check['status'])->toBe('OK')
            ->and($check['difference'])->toBe('0.00');
    }
});
