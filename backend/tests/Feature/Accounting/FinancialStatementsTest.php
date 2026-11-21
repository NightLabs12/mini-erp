<?php

declare(strict_types=1);

use App\Domain\Accounting\Services\BalanceSheetService;
use App\Domain\Accounting\Services\CashBankReportService;
use App\Domain\Accounting\Services\IncomeStatementService;
use App\Domain\Accounting\Services\JournalReportService;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);
    $this->journals = app(JournalService::class);
    $this->from = now()->startOfMonth()->toDateString();
    $this->to = now()->endOfMonth()->toDateString();
    $this->today = now()->toDateString();

    $this->journals->post(
        journalLines('1-1400', '3-1000', '1000.00'),
        'manual',
        CarbonImmutable::parse($this->today),
        'Modal awal persediaan',
    );
    $this->journals->post(
        journalLines('1-1100', '4-1000', '1000.00'),
        'manual',
        CarbonImmutable::parse($this->today),
        'Penjualan tunai',
    );
    $this->journals->post(
        journalLines('5-1000', '1-1400', '400.00'),
        'manual',
        CarbonImmutable::parse($this->today),
        'HPP',
    );
    $this->journals->post(
        journalLines('6-1000', '1-1100', '100.00'),
        'manual',
        CarbonImmutable::parse($this->today),
        'Beban gaji',
    );

    $previous = CarbonImmutable::parse($this->from)->subMonthNoOverflow();
    accountingPeriod((int) $previous->year, (int) $previous->month);
    $this->journals->post(
        journalLines('1-1100', '4-1000', '500.00'),
        'manual',
        $previous->startOfMonth()->addDays(5),
        'Penjualan bulan lalu',
    );
});

it('menyajikan laba rugi multi-level dan komparatif', function (): void {
    $report = app(IncomeStatementService::class)->report($this->from, $this->to);

    expect($report['revenue']['total'])->toBe('1000.00')
        ->and($report['cogs']['total'])->toBe('400.00')
        ->and($report['expense']['total'])->toBe('100.00')
        ->and($report['gross_profit'])->toBe('600.00')
        ->and($report['net_profit'])->toBe('500.00')
        ->and($report['totals']['operating_profit'])->toBe('500.00')
        ->and($report['sections']['revenue']['groups'])->not->toBeEmpty()
        ->and($report['comparison']['totals']['revenue'])->toBe('500.00')
        ->and($report['comparison']['variance']['revenue'])->toBe('500.00');
});

it('menyajikan neraca seimbang dengan laba tahun berjalan dinamis', function (): void {
    $report = app(BalanceSheetService::class)->report($this->today);

    expect($report['balanced'])->toBeTrue()
        ->and($report['totals']['total_assets'])->toBe('2000.00')
        ->and($report['totals']['total_liabilities'])->toBe('0.00')
        ->and($report['totals']['total_equity'])->toBe('2000.00')
        ->and($report['totals']['current_year_profit'])->toBe('1000.00');

    $profit = collect($report['equity'])->firstWhere('code', '3-3000');

    expect($profit)->not->toBeNull()
        ->and($profit['amount'])->toBe('1000.00')
        ->and($profit['dynamic'])->toBeTrue();
});

it('menyajikan laporan kas dan bank dengan saldo berjalan', function (): void {
    $report = app(CashBankReportService::class)->report($this->from, $this->to);
    $cash = collect($report['accounts'])->firstWhere('account.code', '1-1100');

    expect($cash)->not->toBeNull()
        ->and($cash['opening_balance'])->toBe('500.00')
        ->and($cash['total_debit'])->toBe('1000.00')
        ->and($cash['total_credit'])->toBe('100.00')
        ->and($cash['closing_balance'])->toBe('1400.00')
        ->and(array_column($cash['lines'], 'running_balance'))->toBe(['1500.00', '1400.00']);
});

it('menyajikan laporan jurnal dengan baris dan total', function (): void {
    $report = app(JournalReportService::class)->report($this->from, $this->to);

    expect($report['totals']['count'])->toBe(4)
        ->and($report['totals']['debit'])->toBe('2500.00')
        ->and($report['totals']['credit'])->toBe('2500.00')
        ->and($report['journals'][0]['lines'])->not->toBeEmpty();
});

it('mendeteksi selisih rekonsiliasi persediaan', function (): void {
    $report = app(ReconciliationService::class)->report($this->today);
    $inventory = collect($report['checks'])->firstWhere('check', 'inventory');

    expect($report['ok'])->toBeFalse()
        ->and($inventory['status'])->toBe('MISMATCH')
        ->and($inventory['actual'])->toBe('600.00')
        ->and($inventory['expected'])->toBe('0.00');
});
