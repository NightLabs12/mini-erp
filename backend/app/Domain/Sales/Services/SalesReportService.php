<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Laporan sales: SO Outstanding, Sales Summary, AR Outstanding, dan AR Aging.
 * Semua filter memakai parameter binding.
 */
final class SalesReportService
{
    /**
     * @return array<string, mixed>
     */
    public function soOutstanding(?int $customerId, string $from, string $to): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT so.id AS so_id,
                       so.doc_no,
                       so.doc_date,
                       so.status,
                       c.id AS customer_id,
                       c.code AS customer_code,
                       c.name AS customer_name,
                       soi.id AS so_item_id,
                       i.id AS item_id,
                       i.sku,
                       i.name AS item_name,
                       u.code AS unit_code,
                       soi.qty_base,
                       soi.delivered_qty_base,
                       soi.invoiced_qty_base,
                       soi.unit_price_base,
                       (soi.qty_base - soi.delivered_qty_base) AS remaining_qty_base,
                       (soi.qty_base - soi.invoiced_qty_base) AS uninvoiced_qty_base
                  FROM sales_orders so
                  JOIN customers c ON c.id = so.customer_id
                  JOIN sales_order_items soi ON soi.sales_order_id = so.id
                  JOIN items i ON i.id = soi.item_id
                  JOIN units u ON u.id = soi.unit_id
                 WHERE so.status = 'approved'
                   AND so.deleted_at IS NULL
                   AND so.doc_date BETWEEN ? AND ?
                   AND (CAST(? AS bigint) IS NULL OR so.customer_id = CAST(? AS bigint))
                   AND (soi.qty_base > soi.delivered_qty_base OR soi.qty_base > soi.invoiced_qty_base)
                 ORDER BY so.doc_date, so.doc_no, soi.line_no, soi.id
            SQL,
            [$from, $to, $customerId, $customerId],
        );

        $lines = [];
        $totalRemainingQty = '0.0000';
        $totalOutstandingValue = '0.00';

        foreach ($rows as $row) {
            $remaining = $this->qty((string) $row->remaining_qty_base);
            $value = Decimal::round(
                Decimal::mul($remaining, (string) $row->unit_price_base, Decimal::QTY_SCALE + 2),
                Decimal::MONEY_SCALE,
            );

            $totalRemainingQty = Decimal::add($totalRemainingQty, $remaining, Decimal::QTY_SCALE);
            $totalOutstandingValue = Decimal::add($totalOutstandingValue, $value, Decimal::MONEY_SCALE);

            $lines[] = [
                'so_id' => (int) $row->so_id,
                'doc_no' => (string) $row->doc_no,
                'doc_date' => (string) $row->doc_date,
                'status' => (string) $row->status,
                'customer' => [
                    'id' => (int) $row->customer_id,
                    'code' => (string) $row->customer_code,
                    'name' => (string) $row->customer_name,
                ],
                'so_item_id' => (int) $row->so_item_id,
                'item' => [
                    'id' => (int) $row->item_id,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->item_name,
                    'unit_code' => (string) $row->unit_code,
                ],
                'ordered_qty_base' => $this->qty((string) $row->qty_base),
                'delivered_qty_base' => $this->qty((string) $row->delivered_qty_base),
                'remaining_qty_base' => $remaining,
                'invoiced_qty_base' => $this->qty((string) $row->invoiced_qty_base),
                'uninvoiced_qty_base' => $this->qty((string) $row->uninvoiced_qty_base),
                'unit_price_base' => $this->qty((string) $row->unit_price_base),
                'outstanding_value' => $value,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'lines' => $lines,
            'totals' => [
                'count' => count($lines),
                'remaining_qty_base' => $totalRemainingQty,
                'outstanding_value' => $totalOutstandingValue,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salesSummary(?int $customerId, string $from, string $to): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT c.id AS customer_id,
                       c.code AS customer_code,
                       c.name AS customer_name,
                       COUNT(si.id) AS invoice_count,
                       COALESCE(SUM(si.subtotal), 0) AS subtotal,
                       COALESCE(SUM(si.tax_amount), 0) AS tax_amount,
                       COALESCE(SUM(si.total), 0) AS total
                  FROM sales_invoices si
                  JOIN customers c ON c.id = si.customer_id
                 WHERE si.status = 'approved'
                   AND si.deleted_at IS NULL
                   AND si.doc_date BETWEEN ? AND ?
                   AND (CAST(? AS bigint) IS NULL OR si.customer_id = CAST(? AS bigint))
                 GROUP BY c.id, c.code, c.name
                 ORDER BY total DESC, c.code
            SQL,
            [$from, $to, $customerId, $customerId],
        );

        $lines = [];
        $totals = [
            'invoice_count' => 0,
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ];

        foreach ($rows as $row) {
            $subtotal = $this->money((string) $row->subtotal);
            $tax = $this->money((string) $row->tax_amount);
            $total = $this->money((string) $row->total);
            $count = (int) $row->invoice_count;

            $totals['invoice_count'] += $count;
            $totals['subtotal'] = Decimal::add($totals['subtotal'], $subtotal, Decimal::MONEY_SCALE);
            $totals['tax_amount'] = Decimal::add($totals['tax_amount'], $tax, Decimal::MONEY_SCALE);
            $totals['total'] = Decimal::add($totals['total'], $total, Decimal::MONEY_SCALE);

            $lines[] = [
                'customer' => [
                    'id' => (int) $row->customer_id,
                    'code' => (string) $row->customer_code,
                    'name' => (string) $row->customer_name,
                ],
                'invoice_count' => $count,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'lines' => $lines,
            'totals' => $totals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function arOutstanding(?int $customerId, string $asOf): array
    {
        $rows = $this->openInvoices($customerId, $asOf);
        $lines = [];
        $total = '0.00';

        foreach ($rows as $row) {
            $remaining = $this->money((string) $row->remaining);
            $days = max(0, (int) $row->days_overdue);
            $total = Decimal::add($total, $remaining, Decimal::MONEY_SCALE);

            $lines[] = [
                'invoice_id' => (int) $row->invoice_id,
                'doc_no' => (string) $row->doc_no,
                'doc_date' => (string) $row->doc_date,
                'due_date' => (string) $row->due_date,
                'customer' => [
                    'id' => (int) $row->customer_id,
                    'code' => (string) $row->customer_code,
                    'name' => (string) $row->customer_name,
                ],
                'total' => $this->money((string) $row->total),
                'paid_amount' => $this->money((string) $row->paid_amount),
                'remaining' => $remaining,
                'days_overdue' => $days,
            ];
        }

        return [
            'as_of' => $asOf,
            'lines' => $lines,
            'totals' => [
                'count' => count($lines),
                'remaining' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function arAging(?int $customerId, string $asOf): array
    {
        $rows = $this->openInvoices($customerId, $asOf);
        $buckets = [
            'current' => ['label' => 'Current', 'count' => 0, 'amount' => '0.00'],
            '1-30' => ['label' => '1-30 hari', 'count' => 0, 'amount' => '0.00'],
            '31-60' => ['label' => '31-60 hari', 'count' => 0, 'amount' => '0.00'],
            '61-90' => ['label' => '61-90 hari', 'count' => 0, 'amount' => '0.00'],
            '>90' => ['label' => '> 90 hari', 'count' => 0, 'amount' => '0.00'],
        ];
        $lines = [];
        $total = '0.00';

        foreach ($rows as $row) {
            $remaining = $this->money((string) $row->remaining);
            $days = max(0, (int) $row->days_overdue);
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default => '>90',
            };

            $buckets[$bucket]['count']++;
            $buckets[$bucket]['amount'] = Decimal::add(
                $buckets[$bucket]['amount'],
                $remaining,
                Decimal::MONEY_SCALE,
            );
            $total = Decimal::add($total, $remaining, Decimal::MONEY_SCALE);

            $lines[] = [
                'invoice_id' => (int) $row->invoice_id,
                'doc_no' => (string) $row->doc_no,
                'due_date' => (string) $row->due_date,
                'customer' => [
                    'id' => (int) $row->customer_id,
                    'code' => (string) $row->customer_code,
                    'name' => (string) $row->customer_name,
                ],
                'remaining' => $remaining,
                'days_overdue' => $days,
                'bucket' => $bucket,
            ];
        }

        return [
            'as_of' => $asOf,
            'buckets' => array_values($buckets),
            'lines' => $lines,
            'totals' => [
                'count' => count($lines),
                'remaining' => $total,
            ],
        ];
    }

    /**
     * @return list<object>
     */
    private function openInvoices(?int $customerId, string $asOf): array
    {
        return DB::select(
            <<<'SQL'
                SELECT si.id AS invoice_id,
                       si.doc_no,
                       si.doc_date,
                       si.due_date,
                       si.total,
                       si.paid_amount,
                       (si.total - si.paid_amount) AS remaining,
                       c.id AS customer_id,
                       c.code AS customer_code,
                       c.name AS customer_name,
                       (CAST(? AS date) - si.due_date) AS days_overdue
                  FROM sales_invoices si
                  JOIN customers c ON c.id = si.customer_id
                 WHERE si.status = 'approved'
                   AND si.deleted_at IS NULL
                   AND si.doc_date <= ?
                   AND si.total > si.paid_amount
                   AND (CAST(? AS bigint) IS NULL OR si.customer_id = CAST(? AS bigint))
                 ORDER BY si.due_date, si.doc_no
            SQL,
            [$asOf, $asOf, $customerId, $customerId],
        );
    }

    private function qty(string|int $value): string
    {
        return Decimal::round($value, Decimal::QTY_SCALE);
    }

    private function money(string|int $value): string
    {
        return Decimal::round($value, Decimal::MONEY_SCALE);
    }
}
