<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ringkasan dashboard: penjualan/pembelian bulan berjalan, nilai persediaan,
 * AP/AR, grafik penjualan enam bulan, stok di bawah minimum, dan jumlah
 * dokumen yang menunggu approval.
 */
final class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();

        return [
            'period' => [
                'from' => $start,
                'to' => $end,
            ],
            'sales_this_month' => $this->invoiceTotal('sales_invoices', $start, $end),
            'purchases_this_month' => $this->invoiceTotal('purchase_invoices', $start, $end),
            'inventory_value' => $this->inventoryValue(),
            'ap_outstanding' => $this->outstanding('purchase_invoices'),
            'ar_outstanding' => $this->outstanding('sales_invoices'),
            'sales_series' => $this->salesSeries(),
            'low_stock_items' => $this->lowStockItems(),
            'pending_approvals' => $this->pendingApprovals(),
        ];
    }

    private function invoiceTotal(string $table, string $from, string $to): string
    {
        if (! Schema::hasTable($table)) {
            return '0.00';
        }

        $row = DB::selectOne(
            "SELECT COALESCE(SUM(total), 0) AS amount
               FROM {$table}
              WHERE status = 'approved'
                AND deleted_at IS NULL
                AND doc_date BETWEEN ? AND ?",
            [$from, $to],
        );

        return $this->money((string) ($row->amount ?? '0'));
    }

    private function inventoryValue(): string
    {
        if (! Schema::hasTable('stock_balances')) {
            return '0.00';
        }

        $row = DB::selectOne('SELECT COALESCE(SUM(value), 0) AS amount FROM stock_balances');

        return $this->money((string) ($row->amount ?? '0'));
    }

    private function outstanding(string $table): string
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumns($table, ['status', 'total', 'paid_amount'])) {
            return '0.00';
        }

        $row = DB::selectOne(
            "SELECT COALESCE(SUM(total - paid_amount), 0) AS amount
               FROM {$table}
              WHERE status = 'approved'
                AND deleted_at IS NULL
                AND total > paid_amount",
        );

        return $this->money((string) ($row->amount ?? '0'));
    }

    /**
     * @return list<array{month: string, total: string}>
     */
    private function salesSeries(): array
    {
        $series = [];
        $months = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow($i);
            $key = $month->format('Y-m');
            $months[$key] = $month;
            $series[$key] = '0.00';
        }

        if (Schema::hasTable('sales_invoices')) {
            $rows = DB::select(
                "SELECT to_char(doc_date, 'YYYY-MM') AS month,
                        COALESCE(SUM(total), 0) AS amount
                   FROM sales_invoices
                  WHERE status = 'approved'
                    AND deleted_at IS NULL
                    AND doc_date >= ?
                  GROUP BY to_char(doc_date, 'YYYY-MM')",
                [array_key_first($months).'-01'],
            );

            foreach ($rows as $row) {
                $key = (string) $row->month;

                if (array_key_exists($key, $series)) {
                    $series[$key] = $this->money((string) $row->amount);
                }
            }
        }

        $result = [];

        foreach ($series as $key => $total) {
            $result[] = ['month' => $key, 'total' => $total];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lowStockItems(): array
    {
        if (! Schema::hasTable('stock_balances')) {
            return [];
        }

        $rows = DB::select(
            <<<'SQL'
                SELECT i.id,
                       i.sku,
                       i.name,
                       i.min_stock,
                       COALESCE(SUM(sb.qty_base), 0) AS qty_base,
                       u.code AS base_unit_code
                  FROM items i
                  JOIN units u ON u.id = i.base_unit_id
                  LEFT JOIN stock_balances sb ON sb.item_id = i.id
                 WHERE i.deleted_at IS NULL
                   AND i.is_active = true
                   AND i.min_stock > 0
                 GROUP BY i.id, i.sku, i.name, i.min_stock, u.code
                HAVING COALESCE(SUM(sb.qty_base), 0) < i.min_stock
                 ORDER BY (i.min_stock - COALESCE(SUM(sb.qty_base), 0)) DESC, i.sku
                 LIMIT 20
            SQL,
        );

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'sku' => (string) $row->sku,
            'name' => (string) $row->name,
            'min_stock' => (string) $row->min_stock,
            'qty_base' => (string) $row->qty_base,
            'base_unit_code' => (string) $row->base_unit_code,
        ], $rows);
    }

    /**
     * @return array{total: int, by_resource: array<string, int>}
     */
    private function pendingApprovals(): array
    {
        $byResource = [];

        /** @var list<string> $documents */
        $documents = config('master_usage.document_tables', []);

        foreach ($documents as $table) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'status')
                || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            $count = DB::table($table)
                ->where('status', 'draft')
                ->whereNull('deleted_at')
                ->count();

            if ($count > 0) {
                $byResource[$table] = $count;
            }
        }

        if (Schema::hasTable('journals')) {
            $count = DB::table('journals')
                ->where('status', 'draft')
                ->whereNull('deleted_at')
                ->count();

            if ($count > 0) {
                $byResource['journals'] = $count;
            }
        }

        return [
            'total' => array_sum($byResource),
            'by_resource' => $byResource,
        ];
    }

    private function money(string|int $value): string
    {
        return Decimal::round($value, Decimal::MONEY_SCALE);
    }
}
