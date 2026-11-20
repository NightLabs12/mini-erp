<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Services\JournalService;
use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(AccountingSeeder::class);
    $this->service = app(JournalService::class);
    $this->date = CarbonImmutable::parse('2030-01-15');
    accountingPeriod(2030, 1);
});

it('menolak jurnal yang tidak seimbang', function (): void {
    $lines = [
        ['account_id' => accountByCode('1-1100')->getKey(), 'debit' => '100.00'],
        ['account_id' => accountByCode('4-1000')->getKey(), 'credit' => '90.00'],
    ];

    try {
        $this->service->post($lines, 'manual', $this->date);
        $this->fail('Jurnal tidak seimbang seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('JOURNAL_NOT_BALANCED')
            ->and($exception->status())->toBe(422);
    }
});

it('memposting jurnal seimbang dengan nomor JV dan audit', function (): void {
    $journal = $this->service->post(
        journalLines('1-1100', '4-1000', '1000.00'),
        'manual',
        $this->date,
        'Setoran modal awal',
    );

    expect($journal->status)->toBe(Journal::STATUS_POSTED)
        ->and($journal->journal_no)->toStartWith('JV/2030/01/')
        ->and($journal->total_debit)->toBe('1000.00')
        ->and($journal->total_credit)->toBe('1000.00');

    assertJournalBalanced($journal);
});

it('menolak posting ke periode yang sudah ditutup', function (): void {
    accountingPeriod(2030, 2, 'closed');

    try {
        $this->service->post(
            journalLines('1-1100', '4-1000', '100.00'),
            'manual',
            CarbonImmutable::parse('2030-02-10'),
        );
        $this->fail('Posting ke periode closed seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('PERIOD_CLOSED')
            ->and($exception->status())->toBe(422);
    }
});

it('membalik jurnal dengan menukar debit dan credit', function (): void {
    $original = $this->service->post(
        journalLines('1-1100', '4-1000', '750.00'),
        'manual',
        $this->date,
    );

    $reversal = $this->service->reverse($original);

    expect($original->fresh()->status)->toBe(Journal::STATUS_REVERSED)
        ->and($reversal->status)->toBe(Journal::STATUS_POSTED)
        ->and($reversal->reversal_of_id)->toBe($original->getKey())
        ->and($reversal->journal_no)->not->toBe($original->journal_no)
        ->and($reversal->total_debit)->toBe('750.00');

    $reversalLines = $reversal->lines()->get();

    expect($reversalLines[0]->account_id)->toBe(accountByCode('1-1100')->getKey())
        ->and($reversalLines[0]->credit)->toBe('750.00')
        ->and($reversalLines[1]->account_id)->toBe(accountByCode('4-1000')->getKey())
        ->and($reversalLines[1]->debit)->toBe('750.00');

    assertJournalBalanced($original->fresh());
    assertJournalBalanced($reversal);

    expect(fn () => $this->service->reverse($original->fresh()))
        ->toThrow(ApiException::class);
});

it('menolak jurnal posted tidak seimbang lewat trigger database', function (): void {
    $period = accountingPeriod(2030, 1);

    $journal = Journal::query()->create([
        'journal_no' => 'JV/DB/TEST',
        'journal_date' => $this->date->toDateString(),
        'period_id' => $period->getKey(),
        'source_type' => 'manual',
        'status' => Journal::STATUS_POSTED,
        'total_debit' => '100.00',
        'total_credit' => '90.00',
    ]);

    DB::table('journal_lines')->insert([
        'journal_id' => $journal->getKey(),
        'account_id' => accountByCode('1-1100')->getKey(),
        'journal_date' => $this->date->toDateString(),
        'line_no' => 1,
        'debit' => '100.00',
        'credit' => '0.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('journal_lines')->insert([
        'journal_id' => $journal->getKey(),
        'account_id' => accountByCode('4-1000')->getKey(),
        'journal_date' => $this->date->toDateString(),
        'line_no' => 2,
        'debit' => '0.00',
        'credit' => '90.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(function (): void {
        DB::transaction(function (): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    })->toThrow(QueryException::class);
});

it('menyimpan draft tidak seimbang tetapi menolak approve', function (): void {
    $draft = $this->service->createDraft([
        ['account_id' => accountByCode('1-1100')->getKey(), 'debit' => '100.00'],
        ['account_id' => accountByCode('4-1000')->getKey(), 'credit' => '90.00'],
    ], $this->date, 'Draft salah');

    expect($draft->status)->toBe(Journal::STATUS_DRAFT)
        ->and($draft->journal_no)->toBeNull();

    try {
        $this->service->approve($draft);
        $this->fail('Approve draft tidak seimbang seharusnya ditolak.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('JOURNAL_NOT_BALANCED');
    }

    expect($draft->fresh()->status)->toBe(Journal::STATUS_DRAFT);
});

it('memberi nomor JV saat draft disetujui', function (): void {
    $draft = $this->service->createDraft(
        journalLines('1-1100', '4-1000', '125.00'),
        $this->date,
        'Draft benar',
    );

    expect($draft->journal_no)->toBeNull();

    $posted = $this->service->approve($draft);

    expect($posted->status)->toBe(Journal::STATUS_POSTED)
        ->and($posted->journal_no)->toStartWith('JV/2030/01/')
        ->and($posted->approved_at)->not->toBeNull();

    assertJournalBalanced($posted);

    expect(fn () => $this->service->approve($posted))->toThrow(ApiException::class);
});
