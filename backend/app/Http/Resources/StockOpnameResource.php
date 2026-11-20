<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameItem;
use Illuminate\Http\Request;

final class StockOpnameResource extends ApiResource
{
    private readonly StockOpname $opname;

    public function __construct(StockOpname $opname)
    {
        parent::__construct($opname);

        $this->opname = $opname;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->opname->getKey(),
            'doc_no' => $this->opname->doc_no,
            'doc_date' => $this->opname->doc_date?->toDateString(),
            'period_id' => $this->opname->period_id,
            'warehouse_id' => $this->opname->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->opname->warehouse === null ? null : [
                'id' => $this->opname->warehouse->getKey(),
                'code' => $this->opname->warehouse->code,
                'name' => $this->opname->warehouse->name,
            ]),
            'status' => $this->opname->status,
            'notes' => $this->opname->notes,
            'journal_id' => $this->opname->journal_id,
            'journal' => $this->whenLoaded(
                'journal',
                fn (): ?array => $this->opname->journal === null
                    ? null
                    : (new JournalResource($this->opname->journal))->resolve(),
            ),
            'created_by' => $this->opname->created_by,
            'approved_by' => $this->opname->approved_by,
            'approved_at' => $this->opname->approved_at?->toIso8601String(),
            'items' => $this->whenLoaded(
                'items',
                fn (): array => $this->opname->items
                    ->sortBy([['line_no', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(static fn (StockOpnameItem $item): array => (new StockOpnameItemResource($item))->resolve())
                    ->all(),
            ),
            ...$this->timestamps(),
        ];
    }
}
