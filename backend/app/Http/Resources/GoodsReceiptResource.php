<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptItem;
use Illuminate\Http\Request;

final class GoodsReceiptResource extends ApiResource
{
    private readonly GoodsReceipt $receipt;

    public function __construct(GoodsReceipt $receipt)
    {
        parent::__construct($receipt);

        $this->receipt = $receipt;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->receipt->getKey(),
            'doc_no' => $this->receipt->doc_no,
            'doc_date' => $this->receipt->doc_date?->toDateString(),
            'period_id' => $this->receipt->period_id,
            'purchase_order_id' => $this->receipt->purchase_order_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn (): ?array => $this->receipt->purchaseOrder === null ? null : [
                'id' => $this->receipt->purchaseOrder->getKey(),
                'doc_no' => $this->receipt->purchaseOrder->doc_no,
            ]),
            'supplier_id' => $this->receipt->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn (): ?array => $this->receipt->supplier === null ? null : [
                'id' => $this->receipt->supplier->getKey(),
                'code' => $this->receipt->supplier->code,
                'name' => $this->receipt->supplier->name,
            ]),
            'warehouse_id' => $this->receipt->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->receipt->warehouse === null ? null : [
                'id' => $this->receipt->warehouse->getKey(),
                'code' => $this->receipt->warehouse->code,
                'name' => $this->receipt->warehouse->name,
            ]),
            'status' => $this->receipt->status,
            'notes' => $this->receipt->notes,
            'journal_id' => $this->receipt->journal_id,
            'journal' => $this->whenLoaded(
                'journal',
                fn (): ?array => $this->receipt->journal === null
                    ? null
                    : (new JournalResource($this->receipt->journal))->resolve(),
            ),
            'created_by' => $this->receipt->created_by,
            'approved_by' => $this->receipt->approved_by,
            'approved_at' => $this->receipt->approved_at?->toIso8601String(),
            'items' => $this->whenLoaded(
                'items',
                fn (): array => $this->receipt->items
                    ->sortBy([['line_no', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(static fn (GoodsReceiptItem $item): array => (new GoodsReceiptItemResource($item))->resolve())
                    ->all(),
            ),
            ...$this->timestamps(),
        ];
    }
}
