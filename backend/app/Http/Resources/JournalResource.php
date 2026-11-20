<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Models\JournalLine;
use Illuminate\Http\Request;

final class JournalResource extends ApiResource
{
    private readonly Journal $journal;

    public function __construct(Journal $journal)
    {
        parent::__construct($journal);

        $this->journal = $journal;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->journal->getKey(),
            'journal_no' => $this->journal->journal_no,
            'journal_date' => $this->journal->journal_date?->toDateString(),
            'period_id' => $this->journal->period_id,
            'period' => $this->whenLoaded(
                'period',
                fn (): array => (new AccountingPeriodResource($this->journal->period))->resolve(),
            ),
            'source_type' => $this->journal->source_type,
            'source_id' => $this->journal->source_id,
            'description' => $this->journal->description,
            'status' => $this->journal->status,
            'total_debit' => $this->journal->total_debit,
            'total_credit' => $this->journal->total_credit,
            'reversal_of_id' => $this->journal->reversal_of_id,
            'approved_at' => $this->journal->approved_at?->toIso8601String(),
            'lines' => $this->whenLoaded(
                'lines',
                fn (): array => $this->journal->lines
                    ->map(static fn (JournalLine $line): array => (new JournalLineResource($line))->resolve())
                    ->all(),
            ),
            ...$this->timestamps(),
        ];
    }
}
