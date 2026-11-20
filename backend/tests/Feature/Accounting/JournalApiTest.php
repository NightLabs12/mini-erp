<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\Journal;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    seedRbac();
    $this->seed(AccountingSeeder::class);

    $this->admin = userWithRole('admin');
    $this->adminHeaders = bearerFor($this->admin);
    $this->today = now()->toDateString();
});

it('melakukan CRUD draft, approve, reverse, dan audit jurnal manual', function (): void {
    $created = $this->withHeaders($this->adminHeaders)
        ->postJson('/api/v1/journals', [
            'journal_date' => $this->today,
            'description' => 'Jurnal manual uji',
            'lines' => [
                ['account_id' => accountByCode('1-1100')->getKey(), 'debit' => '1000.00'],
                ['account_id' => accountByCode('4-1000')->getKey(), 'credit' => '1000.00'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', Journal::STATUS_DRAFT)
        ->assertJsonPath('data.journal_no', null)
        ->assertJsonCount(2, 'data.lines');

    $id = $created->json('data.id');

    $this->withHeaders($this->adminHeaders)
        ->putJson("/api/v1/journals/{$id}", [
            'description' => 'Jurnal manual diperbarui',
        ])
        ->assertOk()
        ->assertJsonPath('data.description', 'Jurnal manual diperbarui');

    $this->withHeaders($this->adminHeaders)
        ->postJson("/api/v1/journals/{$id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', Journal::STATUS_POSTED)
        ->assertJsonPath('data.description', 'Jurnal manual diperbarui');

    $posted = Journal::query()->findOrFail($id);

    expect($posted->journal_no)->toStartWith('JV/');
    assertJournalBalanced($posted);

    $reversalId = $this->withHeaders($this->adminHeaders)
        ->postJson("/api/v1/journals/{$id}/reverse")
        ->assertCreated()
        ->assertJsonPath('data.reversal_of_id', $id)
        ->json('data.id');

    $this->withHeaders($this->adminHeaders)
        ->getJson("/api/v1/journals/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', Journal::STATUS_REVERSED);

    $this->withHeaders($this->adminHeaders)
        ->getJson("/api/v1/journals/{$reversalId}")
        ->assertOk()
        ->assertJsonPath('data.status', Journal::STATUS_POSTED);

    $events = collect(
        $this->withHeaders($this->adminHeaders)
            ->getJson("/api/v1/journals/{$id}/audit")
            ->assertOk()
            ->json('data')
    )->pluck('event');

    expect($events)->toContain('created')
        ->and($events)->toContain('updated')
        ->and($events)->toContain('posted')
        ->and($events)->toContain('reversed');
});

it('menolak approve jurnal draft yang tidak seimbang', function (): void {
    $id = $this->withHeaders($this->adminHeaders)
        ->postJson('/api/v1/journals', [
            'journal_date' => $this->today,
            'lines' => [
                ['account_id' => accountByCode('1-1100')->getKey(), 'debit' => '100.00'],
                ['account_id' => accountByCode('4-1000')->getKey(), 'credit' => '90.00'],
            ],
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withHeaders($this->adminHeaders)
        ->postJson("/api/v1/journals/{$id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('code', 'JOURNAL_NOT_BALANCED');
});

it('menolak jurnal dengan akun induk non-postable', function (): void {
    $this->withHeaders($this->adminHeaders)
        ->postJson('/api/v1/journals', [
            'journal_date' => $this->today,
            'lines' => [
                ['account_id' => accountByCode('1-0000')->getKey(), 'debit' => '100.00'],
                ['account_id' => accountByCode('4-1000')->getKey(), 'credit' => '100.00'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('menolak viewer membuat jurnal', function (): void {
    $this->withHeaders(bearerFor(userWithRole('viewer')))
        ->postJson('/api/v1/journals', [
            'journal_date' => $this->today,
            'lines' => journalLines('1-1100', '4-1000', '10.00'),
        ])
        ->assertStatus(403);
});

it('mengizinkan finance membuat jurnal dan menutup periode', function (): void {
    $finance = bearerFor(userWithRole('finance'));

    $this->withHeaders($finance)
        ->postJson('/api/v1/journals', [
            'journal_date' => $this->today,
            'lines' => journalLines('1-1100', '4-1000', '10.00'),
        ])
        ->assertCreated();

    $period = accountingPeriod(2031, 1);

    $this->withHeaders($finance)
        ->postJson("/api/v1/accounting-periods/{$period->getKey()}/close")
        ->assertOk()
        ->assertJsonPath('data.status', AccountingPeriod::STATUS_CLOSED);
});

it('menolak finance membuka kembali periode', function (): void {
    $period = accountingPeriod(2031, 1, AccountingPeriod::STATUS_CLOSED);

    $this->withHeaders(bearerFor(userWithRole('finance')))
        ->postJson("/api/v1/accounting-periods/{$period->getKey()}/reopen")
        ->assertStatus(403);
});

it('mengizinkan admin membuka kembali periode', function (): void {
    $period = accountingPeriod(2031, 1, AccountingPeriod::STATUS_CLOSED);

    $this->withHeaders($this->adminHeaders)
        ->postJson("/api/v1/accounting-periods/{$period->getKey()}/reopen")
        ->assertOk()
        ->assertJsonPath('data.status', AccountingPeriod::STATUS_OPEN);
});

it('menolak close periode lewat API bila jurnal tidak seimbang', function (): void {
    $period = accountingPeriod(2031, 2);

    $this->withHeaders($this->adminHeaders)
        ->postJson('/api/v1/journals', [
            'journal_date' => '2031-02-10',
            'lines' => [
                ['account_id' => accountByCode('1-1100')->getKey(), 'debit' => '100.00'],
                ['account_id' => accountByCode('4-1000')->getKey(), 'credit' => '90.00'],
            ],
        ])
        ->assertCreated();

    $this->withHeaders($this->adminHeaders)
        ->postJson("/api/v1/accounting-periods/{$period->getKey()}/close")
        ->assertStatus(409)
        ->assertJsonPath('code', 'PERIOD_CLOSE_FAILED')
        ->assertJsonStructure(['errors' => ['reasons']]);
});
