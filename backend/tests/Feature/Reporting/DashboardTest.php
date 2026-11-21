<?php

declare(strict_types=1);

use App\Domain\Accounting\Services\JournalService;
use App\Domain\Inventory\Services\StockService;
use App\Domain\Master\Models\ItemCategory;
use App\Domain\Master\Models\Warehouse;
use Database\Seeders\AccountingSeeder;

beforeEach(function (): void {
    seedRbac();
    $this->seed(AccountingSeeder::class);

    $this->headers = bearerFor(userWithRole('admin'));
    $this->today = now()->toDateString();

    $pcs = masterUnit('PCS', 'Pcs');
    $category = ItemCategory::query()->create([
        'code' => 'KAOS',
        'name' => 'Kaos Jadi',
        'item_type' => 'trading',
    ]);
    $this->item = masterItem($pcs, [
        'sku' => 'KAOS-POLOS',
        'name' => 'Kaos Polos',
        'category_id' => $category->getKey(),
        'min_stock' => '200',
    ]);
    $warehouse = Warehouse::factory()->create(['code' => 'GD-UTM']);

    app(StockService::class)->receive(
        $this->item,
        $warehouse,
        '100',
        '10000',
        ['type' => 'GR', 'id' => 1],
        $this->today,
    );

    app(JournalService::class)->createDraft(
        journalLines('1-1100', '4-1000', '100.00'),
        $this->today,
        'Draft dashboard',
    );
});

it('menyajikan ringkasan dashboard', function (): void {
    $response = $this->withHeaders($this->headers)
        ->getJson('/api/v1/dashboard/summary')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'period' => ['from', 'to'],
                'sales_this_month',
                'purchases_this_month',
                'inventory_value',
                'ap_outstanding',
                'ar_outstanding',
                'sales_series',
                'low_stock_items',
                'pending_approvals' => ['total', 'by_resource'],
            ],
        ]);

    expect($response->json('data.inventory_value'))->toBe('1000000.00')
        ->and($response->json('data.sales_this_month'))->toBe('0.00')
        ->and($response->json('data.sales_series'))->toHaveCount(6)
        ->and($response->json('data.low_stock_items.0.sku'))->toBe('KAOS-POLOS')
        ->and($response->json('data.pending_approvals.by_resource.journals'))->toBe(1);
});

it('mengizinkan role operasional melihat dashboard', function (): void {
    $this->withHeaders(bearerFor(userWithRole('sales')))
        ->getJson('/api/v1/dashboard/summary')
        ->assertOk();
});
