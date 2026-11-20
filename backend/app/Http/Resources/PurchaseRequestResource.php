<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Purchasing\Models\PurchaseRequest;
use App\Domain\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Http\Request;

final class PurchaseRequestResource extends ApiResource
{
    private readonly PurchaseRequest $request;

    public function __construct(PurchaseRequest $request)
    {
        parent::__construct($request);

        $this->request = $request;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->request->getKey(),
            'doc_no' => $this->request->doc_no,
            'doc_date' => $this->request->doc_date?->toDateString(),
            'period_id' => $this->request->period_id,
            'supplier_id' => $this->request->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn (): ?array => $this->request->supplier === null ? null : [
                'id' => $this->request->supplier->getKey(),
                'code' => $this->request->supplier->code,
                'name' => $this->request->supplier->name,
            ]),
            'warehouse_id' => $this->request->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->request->warehouse === null ? null : [
                'id' => $this->request->warehouse->getKey(),
                'code' => $this->request->warehouse->code,
                'name' => $this->request->warehouse->name,
            ]),
            'status' => $this->request->status,
            'notes' => $this->request->notes,
            'created_by' => $this->request->created_by,
            'approved_by' => $this->request->approved_by,
            'approved_at' => $this->request->approved_at?->toIso8601String(),
            'items' => $this->whenLoaded(
                'items',
                fn (): array => $this->request->items
                    ->sortBy([['line_no', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(static fn (PurchaseRequestItem $item): array => (new PurchaseRequestItemResource($item))->resolve())
                    ->all(),
            ),
            ...$this->timestamps(),
        ];
    }
}
