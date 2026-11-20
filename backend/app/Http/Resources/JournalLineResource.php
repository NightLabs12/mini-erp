<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Accounting\Models\JournalLine;
use Illuminate\Http\Request;

final class JournalLineResource extends ApiResource
{
    private readonly JournalLine $line;

    public function __construct(JournalLine $line)
    {
        parent::__construct($line);

        $this->line = $line;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->line->getKey(),
            'account_id' => $this->line->account_id,
            'account' => $this->whenLoaded(
                'account',
                fn (): array => (new AccountResource($this->line->account))->resolve(),
            ),
            'journal_date' => $this->line->journal_date?->toDateString(),
            'line_no' => $this->line->line_no,
            'debit' => $this->line->debit,
            'credit' => $this->line->credit,
            'description' => $this->line->description,
            'partner_type' => $this->line->partner_type,
            'partner_id' => $this->line->partner_id,
        ];
    }
}
