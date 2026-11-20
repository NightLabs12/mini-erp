<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\PeriodClosingService;
use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);
    $this->closing = app(PeriodClosingService::class);
    $this->journals = app(JournalService::class);
});

it('menutup periode setelah seluruh validasi terpenuhi', function (): void {
    $period = accountingPeriod(2030, 1);

    $closed = $this->closing->close($period);

    expect($closed->status)->toBe(AccountingPeriod::STATUS_CLOSED)
        ->and($closed->closed_at)->not->toBeNull();
});

it('menolak menutup periode bila periode sebelumnya masih open', function (): void {
    accountingPeriod(2030, 1);
    $february = accountingPeriod(2030, 2);

    try {
        $this->closing->close($february);
        $this->fail('Periode Februari seharusnya tidak dapat ditutup.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_CLOSE_FAILED')
            ->and($exception->errors()['reasons'][0])->toContain('2030-01');
    }
});

it('menolak menutup periode bila ada jurnal tidak seimbang', function (): void {
    $period = accountingPeriod(2030, 1);

    $this->journals->createDraft([
        ['account_id' => accountByCode('1-1100')->getKey(), 'debit' => '100.00'],
        ['account_id' => accountByCode('4-1000')->getKey(), 'credit' => '90.00'],
    ], CarbonImmutable::parse('2030-01-10'), 'Draft tidak seimbang');

    try {
        $this->closing->close($period);
        $this->fail('Periode dengan jurnal tidak seimbang seharusnya gagal ditutup.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_CLOSE_FAILED');

        $reasons = implode(' ', $exception->errors()['reasons']);

        expect($reasons)->toContain('tidak seimbang');
    }

    expect($period->fresh()->status)->toBe(AccountingPeriod::STATUS_OPEN);
});

it('menolak menutup periode bila masih ada jurnal draft', function (): void {
    $period = accountingPeriod(2030, 1);

    $this->journals->createDraft(
        journalLines('1-1100', '4-1000', '100.00'),
        CarbonImmutable::parse('2030-01-10'),
        'Draft seimbang',
    );

    try {
        $this->closing->close($period);
        $this->fail('Periode dengan draft seharusnya gagal ditutup.');
    } catch (ApiException $exception) {
        expect(implode(' ', $exception->errors()['reasons']))->toContain('draft');
    }
});

it('membuka kembali periode dan mencatat pelakunya', function (): void {
    $period = accountingPeriod(2030, 1, AccountingPeriod::STATUS_CLOSED);

    $reopened = $this->closing->reopen($period);

    expect($reopened->status)->toBe(AccountingPeriod::STATUS_OPEN)
        ->and($reopened->closed_at)->toBeNull()
        ->and($reopened->reopened_at)->not->toBeNull();
});

it('menolak reopen bila periode setelahnya sudah ditutup', function (): void {
    $january = accountingPeriod(2030, 1);
    $february = accountingPeriod(2030, 2);

    $this->closing->close($january);
    $this->closing->close($february);

    try {
        $this->closing->reopen($january->fresh());
        $this->fail('Reopen Januari seharusnya ditolak karena Februari closed.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_LATER_CLOSED');
    }
});
