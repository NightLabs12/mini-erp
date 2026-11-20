<?php

declare(strict_types=1);

use App\Domain\Accounting\Services\JournalService;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    seedRbac();
    $this->seed(AccountingSeeder::class);

    $this->headers = bearerFor(userWithRole('admin'));
    $this->date = now()->toDateString();

    app(JournalService::class)->post(
        journalLines('1-1100', '4-1000', '100.00'),
        'manual',
        $this->date,
    );
});

it('menyajikan General Ledger dan Trial Balance lewat API', function (): void {
    $from = now()->startOfMonth()->toDateString();
    $to = now()->endOfMonth()->toDateString();

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/reports/general-ledger?from={$from}&to={$to}&account_id=".accountByCode('1-1100')->getKey())
        ->assertOk()
        ->assertJsonPath('data.accounts.0.account.code', '1-1100')
        ->assertJsonPath('data.accounts.0.closing_balance', '100.00');

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/reports/trial-balance?from={$from}&to={$to}")
        ->assertOk()
        ->assertJsonPath('data.balanced', true)
        ->assertJsonPath('data.totals.debit', '100.00')
        ->assertJsonPath('data.totals.credit', '100.00');
});

it('memvalidasi rentang tanggal laporan', function (): void {
    $this->withHeaders($this->headers)
        ->getJson('/api/v1/reports/general-ledger?from=2026-12-31&to=2026-01-01')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['to']]);
});
