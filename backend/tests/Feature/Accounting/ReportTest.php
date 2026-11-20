<?php

declare(strict_types=1);

use App\Domain\Accounting\Services\GeneralLedgerService;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\TrialBalanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);
    accountingPeriod(2029, 12);
    accountingPeriod(2030, 1);

    $journals = app(JournalService::class);

    $journals->post(journalLines('1-1100', '4-1000', '500.00'), 'manual', CarbonImmutable::parse('2029-12-20'));
    $journals->post(journalLines('1-1100', '4-1000', '1000.00'), 'manual', CarbonImmutable::parse('2030-01-05'));
    $journals->post(journalLines('1-1100', '4-1000', '250.00'), 'manual', CarbonImmutable::parse('2030-01-10'));
    $journals->post(journalLines('6-1000', '1-1100', '300.00'), 'manual', CarbonImmutable::parse('2030-01-15'));
});

it('menghitung saldo berjalan General Ledger dengan benar', function (): void {
    $report = app(GeneralLedgerService::class)->report(
        accountByCode('1-1100')->getKey(),
        '2030-01-01',
        '2030-01-31',
    );

    expect($report['accounts'])->toHaveCount(1);

    $cash = $report['accounts'][0];

    expect($cash['opening_balance'])->toBe('500.00')
        ->and($cash['total_debit'])->toBe('1250.00')
        ->and($cash['total_credit'])->toBe('300.00')
        ->and($cash['closing_balance'])->toBe('1450.00')
        ->and(array_column($cash['lines'], 'running_balance'))->toBe(['1500.00', '1750.00', '1450.00']);
});

it('menampilkan saldo kredit sebagai nilai negatif pada GL', function (): void {
    $report = app(GeneralLedgerService::class)->report(
        accountByCode('4-1000')->getKey(),
        '2030-01-01',
        '2030-01-31',
    );

    $revenue = $report['accounts'][0];

    expect($revenue['opening_balance'])->toBe('-500.00')
        ->and($revenue['total_debit'])->toBe('0.00')
        ->and($revenue['total_credit'])->toBe('1250.00')
        ->and($revenue['closing_balance'])->toBe('-1750.00');
});

it('memasukkan jurnal asal dan pembaliknya ke saldo berjalan', function (): void {
    $original = App\Domain\Accounting\Models\Journal::query()
        ->whereDate('journal_date', '2030-01-05')
        ->firstOrFail();

    app(JournalService::class)->reverse($original);

    $report = app(GeneralLedgerService::class)->report(
        accountByCode('1-1100')->getKey(),
        '2030-01-01',
        '2030-01-31',
    );

    $cash = $report['accounts'][0];

    // Saldo awal 500 + 1.000 (asal) + 250 - 300 - 1.000 (pembalik) = 450.
    expect($cash['opening_balance'])->toBe('500.00')
        ->and($cash['total_debit'])->toBe('1250.00')
        ->and($cash['total_credit'])->toBe('1300.00')
        ->and($cash['closing_balance'])->toBe('450.00');
});

it('menghasilkan Trial Balance yang seimbang', function (): void {
    $report = app(TrialBalanceService::class)->report('2030-01-01', '2030-01-31');

    expect($report['balanced'])->toBeTrue()
        ->and($report['totals']['opening_debit'])->toBe('500.00')
        ->and($report['totals']['opening_credit'])->toBe('500.00')
        ->and($report['totals']['debit'])->toBe('1550.00')
        ->and($report['totals']['credit'])->toBe('1550.00')
        ->and($report['totals']['closing_debit'])->toBe('1750.00')
        ->and($report['totals']['closing_credit'])->toBe('1750.00');

    $byCode = collect($report['accounts'])->keyBy('code');

    expect($byCode['1-1100']['closing_debit'])->toBe('1450.00')
        ->and($byCode['4-1000']['closing_credit'])->toBe('1750.00')
        ->and($byCode['6-1000']['closing_debit'])->toBe('300.00');
});
