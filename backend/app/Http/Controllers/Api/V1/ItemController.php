<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Master\Models\Item;
use App\Domain\Master\Services\MasterUsageGuard;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\ItemRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ItemResource;
use App\Support\Math\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class ItemController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly MasterUsageGuard $usageGuard) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', Item::class);

        $paginator = $this->paginateListQuery(Item::query()->with(['category', 'baseUnit']), $request, [
            'sortable' => ['sku', 'name', 'min_stock', 'is_active', 'created_at'],
            'filterable' => ['sku', 'name', 'category_id', 'base_unit_id', 'is_active', 'created_at'],
            'searchable' => ['sku', 'name'],
            'default_sort' => 'sku',
        ]);

        return new ApiCollection($paginator, ItemResource::class);
    }

    public function store(ItemRequest $request): JsonResponse
    {
        $this->authorize('create', Item::class);

        $data = $request->validated();

        $item = DB::transaction(function () use ($data): Item {
            $item = Item::query()->create([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'category_id' => $data['category_id'],
                'base_unit_id' => $data['base_unit_id'],
                'min_stock' => $data['min_stock'] ?? '0',
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncConversions($item, $data['conversions'] ?? []);

            return $item;
        });

        return (new ItemResource($this->loadItem($item)))->response()->setStatusCode(201);
    }

    public function show(Item $item): ItemResource
    {
        $this->authorize('view', $item);

        return new ItemResource($this->loadItem($item));
    }

    public function update(ItemRequest $request, Item $item): ItemResource
    {
        $this->authorize('update', $item);

        $data = $request->validated();

        DB::transaction(function () use ($data, $item): void {
            if ($this->changesStructure($item, $data)) {
                $this->usageGuard->assertNotUsedByDocuments(
                    $item,
                    'Satuan base atau faktor konversi tidak dapat diubah karena item sudah dipakai dokumen.',
                );
            }

            $item->fill(array_intersect_key($data, array_flip([
                'sku', 'name', 'category_id', 'base_unit_id', 'min_stock', 'is_active',
            ])))->save();

            if (array_key_exists('conversions', $data)) {
                $this->syncConversions($item, $data['conversions']);
            }
        });

        return new ItemResource($this->loadItem($item->fresh() ?? $item));
    }

    public function audit(Request $request, Item $item): ApiCollection
    {
        $this->authorize('view', $item);

        return $this->auditTrailFor($item, $request);
    }

    public function destroy(Item $item): Response
    {
        $this->authorize('delete', $item);

        $this->usageGuard->assertUnused($item);

        DB::transaction(function () use ($item): void {
            $item->conversions()->delete();
            $item->delete();
        });

        return response()->noContent();
    }

    /**
     * Perubahan yang membuat qty_base dokumen lama tidak lagi dapat
     * direproduksi: satuan base berganti atau faktor konversi berubah.
     *
     * @param  array<string, mixed>  $data
     */
    private function changesStructure(Item $item, array $data): bool
    {
        if (array_key_exists('base_unit_id', $data)
            && (int) $data['base_unit_id'] !== (int) $item->base_unit_id) {
            return true;
        }

        $conversions = $data['conversions'] ?? null;

        if (! is_array($conversions)) {
            return false;
        }

        $existing = $item->conversions()->get()->keyBy(
            static fn ($conversion): int => (int) $conversion->unit_id,
        );

        $kept = [];

        foreach ($conversions as $row) {
            if (! is_array($row) || ! isset($row['unit_id'])) {
                continue;
            }

            $unitId = (int) $row['unit_id'];
            $kept[] = $unitId;
            $current = $existing->get($unitId);

            if ($current === null || ! isset($row['factor_to_base'])) {
                continue;
            }

            if (Decimal::compare((string) $current->factor_to_base, (string) $row['factor_to_base']) !== 0) {
                return true;
            }
        }

        // Menghapus definisi satuan juga membuat dokumen lama tidak dapat
        // direproduksi, jadi diperlakukan sebagai perubahan struktural.
        foreach ($existing->keys() as $unitId) {
            if (! in_array((int) $unitId, $kept, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Menyelaraskan konversi dengan payload: baris yang sudah ada diperbarui,
     * unit baru dibuat, dan unit yang tidak lagi disebut dihapus.
     *
     * @param  array<int, array<string, mixed>>  $conversions
     */
    private function syncConversions(Item $item, array $conversions): void
    {
        $keep = [];

        foreach ($conversions as $row) {
            $conversion = $item->conversions()->updateOrCreate(
                ['unit_id' => $row['unit_id']],
                [
                    'factor_to_base' => $row['factor_to_base'],
                    'is_purchase_default' => $row['is_purchase_default'] ?? false,
                    'is_sales_default' => $row['is_sales_default'] ?? false,
                ],
            );

            $keep[] = $conversion->getKey();
        }

        $removed = $item->conversions();

        if ($keep !== []) {
            $removed->whereKeyNot($keep);
        }

        $removed->delete();
    }

    private function loadItem(Item $item): Item
    {
        return $item->refresh()->load(['category', 'baseUnit', 'conversions.unit']);
    }
}
