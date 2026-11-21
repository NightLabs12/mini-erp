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
    $this->from = now()->startOfMonth()->toDateString();
    $this->to = now()->endOfMonth()->toDateString();
    $this->asOf = now()->toDateString();

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
    ]);
    $this->warehouse = Warehouse::factory()->create(['code' => 'GD-UTM']);

    app(StockService::class)->receive(
        $this->item,
        $this->warehouse,
        '100',
        '10000',
        ['type' => 'GR', 'id' => 1],
        $this->asOf,
    );

    app(JournalService::class)->post(
        journalLines('1-1100', '4-1000', '1000.00'),
        'manual',
        $this->asOf,
    );
});

/**
 * @return list<array{0: string, 1: array<string, string>}>
 */
function reportCases(): array
{
    $range = [
        'from' => test()->from,
        'to' => test()->to,
        'as_of' => test()->asOf,
    ];

    return [
        ['general-ledger', $range],
        ['trial-balance', $range],
        ['income-statement', $range],
        ['balance-sheet', $range],
        ['cash-bank', $range],
        ['journal', $range],
        ['stock-card', [...$range, 'item_id' => (string) test()->item->getKey()]],
        ['stock-position', $range],
        ['stock-movement', $range],
        ['ap-aging', $range],
        ['ar-aging', $range],
        ['ap-outstanding', $range],
        ['ar-outstanding', $range],
        ['sales-summary', $range],
        ['purchase-summary', $range],
        ['so-outstanding', $range],
        ['po-outstanding', $range],
        ['reconciliation', $range],
    ];
}

it('menyajikan JSON untuk seluruh kode laporan', function (): void {
    foreach (reportCases() as [$code, $params]) {
        $this->withHeaders($this->headers)
            ->getJson("/api/v1/reports/{$code}?".http_build_query($params))
            ->assertOk()
            ->assertJsonStructure(['data']);
    }
});

it('mengekspor Excel dan PDF untuk seluruh kode laporan', function (): void {
    foreach (reportCases() as [$code, $params]) {
        $this->withHeaders($this->headers)
            ->get("/api/v1/reports/{$code}?".http_build_query([...$params, 'format' => 'xlsx']))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->withHeaders($this->headers)
            ->get("/api/v1/reports/{$code}?".http_build_query([...$params, 'format' => 'pdf']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
});

it('menolak laporan tidak dikenal dan format tidak valid', function (): void {
    $this->withHeaders($this->headers)
        ->getJson('/api/v1/reports/unknown-report')
        ->assertStatus(404)
        ->assertJsonPath('code', 'REPORT_NOT_FOUND');

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/reports/trial-balance?format=csv')
        ->assertStatus(422);
});

it('mewajibkan item_id untuk kartu stok', function (): void {
    $this->withHeaders($this->headers)
        ->getJson('/api/v1/reports/stock-card?from='.$this->from.'&to='.$this->to)
        ->assertStatus(422)
        ->assertJsonPath('code', 'REPORT_PARAMETER_REQUIRED');
});

it('menolak laporan bagi role tanpa permission', function (): void {
    $this->app['auth']->forgetGuards();

    $this->withHeaders(bearerFor(userWithRole('warehouse')))
        ->getJson('/api/v1/reports/income-statement?from='.$this->from.'&to='.$this->to)
        ->assertStatus(403);
});
