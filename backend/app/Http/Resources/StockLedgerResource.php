<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\Models\StockLedger;
use Illuminate\Http\Request;

final class StockLedgerResource extends ApiResource
{
    private readonly StockLedger $ledger;

    public function __construct(StockLedger $ledger)
    {
        parent::__construct($ledger);

        $this->ledger = $ledger;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->ledger->getKey(),
            'item_id' => $this->ledger->item_id,
            'item' => $this->whenLoaded('item', fn (): ?array => $this->ledger->item === null ? null : [
                'id' => $this->ledger->item->getKey(),
                'sku' => $this->ledger->item->sku,
                'name' => $this->ledger->item->name,
                'base_unit_id' => $this->ledger->item->base_unit_id,
            ]),
            'warehouse_id' => $this->ledger->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->ledger->warehouse === null ? null : [
                'id' => $this->ledger->warehouse->getKey(),
                'code' => $this->ledger->warehouse->code,
                'name' => $this->ledger->warehouse->name,
            ]),
            'movement_date' => $this->ledger->movement_date?->toDateString(),
            'doc_type' => $this->ledger->doc_type,
            'doc_id' => $this->ledger->doc_id,
            'doc_line_id' => $this->ledger->doc_line_id,
            'qty_in_base' => $this->ledger->qty_in_base,
            'qty_out_base' => $this->ledger->qty_out_base,
            'unit_cost' => $this->ledger->unit_cost,
            'total_value' => $this->ledger->total_value,
            'balance_qty' => $this->ledger->balance_qty,
            'balance_value' => $this->ledger->balance_value,
            'avg_cost_after' => $this->ledger->avg_cost_after,
            'reversal_of_id' => $this->ledger->reversal_of_id,
            'created_by' => $this->ledger->created_by,
            'created_at' => $this->ledger->created_at?->toIso8601String(),
        ];
    }
}
