<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Master\Models\SellingPrice;
use App\Domain\Master\Services\MasterUsageGuard;
use App\Domain\Master\Services\SellingPricePeriodGuard;
use App\Domain\Master\Services\SellingPriceResolver;
use App\Domain\Master\Services\UnitConversionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\SellingPriceLookupRequest;
use App\Http\Requests\Master\SellingPriceRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\SellingPriceResource;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class SellingPriceController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(
        private readonly MasterUsageGuard $usageGuard,
        private readonly SellingPriceResolver $resolver,
        private readonly SellingPricePeriodGuard $periods,
        private readonly UnitConversionService $conversions,
    ) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', SellingPrice::class);

        $paginator = $this->paginateListQuery(SellingPrice::query()->with(['unit', 'item', 'customer']), $request, [
            'sortable' => ['item_id', 'unit_id', 'customer_id', 'price', 'valid_from', 'created_at'],
            'filterable' => ['item_id', 'unit_id', 'customer_id', 'valid_from', 'valid_to', 'created_at'],
            'default_sort' => '-id',
        ]);

        return new ApiCollection($paginator, SellingPriceResource::class);
    }

    public function store(SellingPriceRequest $request): JsonResponse
    {
        $this->authorize('create', SellingPrice::class);

        $data = $request->validated();

        try {
            $price = DB::transaction(function () use ($data): SellingPrice {
                $this->periods->assertNoOverlap(
                    (int) $data['item_id'],
                    (int) $data['unit_id'],
                    isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                    $data['valid_from'] ?? null,
                    $data['valid_to'] ?? null,
                );

                return SellingPrice::query()->create($data);
            });
        } catch (QueryException $exception) {
            throw $this->overlapOrRethrow($exception);
        }

        return (new SellingPriceResource(($price->fresh() ?? $price)->load('unit')))->response()->setStatusCode(201);
    }

    public function show(SellingPrice $sellingPrice): SellingPriceResource
    {
        $this->authorize('view', $sellingPrice);

        return new SellingPriceResource($sellingPrice->load(['unit', 'item', 'customer']));
    }

    public function update(SellingPriceRequest $request, SellingPrice $sellingPrice): SellingPriceResource
    {
        $this->authorize('update', $sellingPrice);

        $data = $request->validated();

        try {
            DB::transaction(function () use ($data, $sellingPrice): void {
                $this->periods->assertNoOverlap(
                    (int) ($data['item_id'] ?? $sellingPrice->item_id),
                    (int) ($data['unit_id'] ?? $sellingPrice->unit_id),
                    array_key_exists('customer_id', $data)
                        ? ($data['customer_id'] === null ? null : (int) $data['customer_id'])
                        : $sellingPrice->customer_id,
                    array_key_exists('valid_from', $data) ? $data['valid_from'] : $sellingPrice->valid_from,
                    array_key_exists('valid_to', $data) ? $data['valid_to'] : $sellingPrice->valid_to,
                    (int) $sellingPrice->getKey(),
                );

                $sellingPrice->update($data);
            });
        } catch (QueryException $exception) {
            throw $this->overlapOrRethrow($exception);
        }

        return new SellingPriceResource(($sellingPrice->fresh() ?? $sellingPrice)->load('unit'));
    }

    public function audit(Request $request, SellingPrice $sellingPrice): ApiCollection
    {
        $this->authorize('view', $sellingPrice);

        return $this->auditTrailFor($sellingPrice, $request);
    }

    public function destroy(SellingPrice $sellingPrice): Response
    {
        $this->authorize('delete', $sellingPrice);

        $this->usageGuard->assertUnused($sellingPrice);

        $sellingPrice->delete();

        return response()->noContent();
    }

    /**
     * Constraint exclusion PostgreSQL menangkap balapan antar-request. Ubah
     * pelanggarannya menjadi 422 yang sama dengan validasi aplikasi.
     */
    private function overlapOrRethrow(QueryException $exception): ApiException
    {
        if ($this->periods->isOverlapViolation($exception)) {
            return $this->periods->overlapError();
        }

        throw $exception;
    }

    /**
     * Harga yang berlaku untuk item, customer, tanggal, dan satuan tertentu.
     */
    public function lookup(SellingPriceLookupRequest $request): JsonResponse
    {
        $this->authorize('viewAny', SellingPrice::class);

        $data = $request->validated();

        $price = $this->resolver->find(
            (int) $data['item_id'],
            isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            $request->lookupDate(),
            isset($data['unit_id']) ? (int) $data['unit_id'] : null,
        );

        if ($price === null) {
            throw ApiException::notFound(
                'Tidak ada harga jual yang berlaku untuk kriteria tersebut.',
                'SELLING_PRICE_NOT_FOUND',
            );
        }

        $price->loadMissing(['item', 'unit']);

        $unitPriceBase = $this->conversions->unitCostPerBase(
            $price->item,
            $price->unit,
            $price->price,
        );

        return response()->json([
            'data' => (new SellingPriceResource($price, $unitPriceBase))->resolve(),
        ]);
    }
}
