<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Models\ItemUnitConversion;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\ItemUnitConversionRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ItemUnitConversionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Konversi satuan per item.
 *
 * Item juga menerima daftar konversi saat create/update. Endpoint ini
 * melengkapi untuk penyesuaian satuan satu per satu tanpa mengirim ulang
 * seluruh item.
 */
final class ItemUnitConversionController extends Controller
{
    public function index(Request $request, Item $item): ApiCollection
    {
        $this->authorize('view', $item);

        $paginator = $this->paginateListQuery(
            $item->conversions()->getQuery()->with('unit'),
            $request,
            [
                'sortable' => ['unit_id', 'factor_to_base', 'created_at'],
                'filterable' => ['unit_id', 'is_purchase_default', 'is_sales_default'],
                'default_sort' => 'unit_id',
                'per_page' => 50,
            ],
        );

        return new ApiCollection($paginator, ItemUnitConversionResource::class);
    }

    public function store(ItemUnitConversionRequest $request, Item $item): JsonResponse
    {
        $this->authorize('update', $item);

        $conversion = $item->conversions()->create($request->validated());

        return (new ItemUnitConversionResource($conversion->fresh(['unit']) ?? $conversion))->response()->setStatusCode(201);
    }

    public function update(
        ItemUnitConversionRequest $request,
        Item $item,
        ItemUnitConversion $conversion,
    ): ItemUnitConversionResource {
        $this->authorize('update', $item);
        $this->ensureBelongsTo($item, $conversion);

        $conversion->update($request->validated());

        return new ItemUnitConversionResource($conversion->fresh(['unit']) ?? $conversion);
    }

    public function destroy(Item $item, ItemUnitConversion $conversion): Response
    {
        $this->authorize('update', $item);
        $this->ensureBelongsTo($item, $conversion);

        $conversion->delete();

        return response()->noContent();
    }

    private function ensureBelongsTo(Item $item, ItemUnitConversion $conversion): void
    {
        if ((int) $conversion->item_id !== (int) $item->getKey()) {
            throw ApiException::notFound('Konversi tidak termasuk item tersebut.', 'CONVERSION_NOT_FOUND');
        }
    }
}
