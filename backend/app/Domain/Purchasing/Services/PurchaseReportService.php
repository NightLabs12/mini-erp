<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Support\Math\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Laporan purchasing: PO Outstanding, Purchase Summary, AP Outstanding, dan
 * AP Aging. Semua filter memakai parameter binding.
 */
final class PurchaseReportService
{
    /**
     * @return array<string, mixed>
     */
    public function poOutstanding(?int $supplierId, string $from, string $to): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT po.id AS po_id,
                       po.doc_no,
                       po.doc_date,
                       po.status,
                       s.id AS supplier_id,
                       s.code AS supplier_code,
                       s.name AS supplier_name,
                       poi.id AS po_item_id,
                       i.id AS item_id,
                       i.sku,
                       i.name AS item_name,
                       u.code AS unit_code,
                       poi.qty_base,
                       poi.received_qty_base,
                       poi.invoiced_qty_base,
                       poi.unit_price_base,
                       (poi.qty_base - poi.received_qty_base) AS remaining_qty_base,
                       (poi.qty_base - poi.invoiced_qty_base) AS uninvoiced_qty_base
                  FROM purchase_orders po
                  JOIN suppliers s ON s.id = po.supplier_id
                  JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
                  JOIN items i ON i.id = poi.item_id
                  JOIN units u ON u.id = poi.unit_id
                 WHERE po.status = 'approved'
                   AND po.deleted_at IS NULL
                   AND po.doc_date BETWEEN ? AND ?
                   AND (CAST(? AS bigint) IS NULL OR po.supplier_id = CAST(? AS bigint))
                   AND (poi.qty_base > poi.received_qty_base OR poi.qty_base > poi.invoiced_qty_base)
                 ORDER BY po.doc_date, po.doc_no, poi.line_no, poi.id
            SQL,
            [$from, $to, $supplierId, $supplierId],
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
                'po_id' => (int) $row->po_id,
                'doc_no' => (string) $row->doc_no,
                'doc_date' => (string) $row->doc_date,
                'status' => (string) $row->status,
                'supplier' => [
                    'id' => (int) $row->supplier_id,
                    'code' => (string) $row->supplier_code,
                    'name' => (string) $row->supplier_name,
                ],
                'po_item_id' => (int) $row->po_item_id,
                'item' => [
                    'id' => (int) $row->item_id,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->item_name,
                    'unit_code' => (string) $row->unit_code,
                ],
                'ordered_qty_base' => $this->qty((string) $row->qty_base),
                'received_qty_base' => $this->qty((string) $row->received_qty_base),
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
    public function purchaseSummary(?int $supplierId, string $from, string $to): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT s.id AS supplier_id,
                       s.code AS supplier_code,
                       s.name AS supplier_name,
                       COUNT(pi.id) AS invoice_count,
                       COALESCE(SUM(pi.subtotal), 0) AS subtotal,
                       COALESCE(SUM(pi.tax_amount), 0) AS tax_amount,
                       COALESCE(SUM(pi.total), 0) AS total
                  FROM purchase_invoices pi
                  JOIN suppliers s ON s.id = pi.supplier_id
                 WHERE pi.status = 'approved'
                   AND pi.deleted_at IS NULL
                   AND pi.doc_date BETWEEN ? AND ?
                   AND (CAST(? AS bigint) IS NULL OR pi.supplier_id = CAST(? AS bigint))
                 GROUP BY s.id, s.code, s.name
                 ORDER BY total DESC, s.code
            SQL,
            [$from, $to, $supplierId, $supplierId],
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
                'supplier' => [
                    'id' => (int) $row->supplier_id,
                    'code' => (string) $row->supplier_code,
                    'name' => (string) $row->supplier_name,
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
    public function apOutstanding(?int $supplierId, string $asOf): array
    {
        $rows = $this->openInvoices($supplierId, $asOf);
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
                'supplier' => [
                    'id' => (int) $row->supplier_id,
                    'code' => (string) $row->supplier_code,
                    'name' => (string) $row->supplier_name,
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
    public function apAging(?int $supplierId, string $asOf): array
    {
        $rows = $this->openInvoices($supplierId, $asOf);
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
                'supplier' => [
                    'id' => (int) $row->supplier_id,
                    'code' => (string) $row->supplier_code,
                    'name' => (string) $row->supplier_name,
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
    private function openInvoices(?int $supplierId, string $asOf): array
    {
        $rows = DB::select(
            <<<'SQL'
                SELECT pi.id AS invoice_id,
                       pi.doc_no,
                       pi.doc_date,
                       pi.due_date,
                       pi.total,
                       pi.paid_amount,
                       (pi.total - pi.paid_amount) AS remaining,
                       s.id AS supplier_id,
                       s.code AS supplier_code,
                       s.name AS supplier_name,
                       (CAST(? AS date) - pi.due_date) AS days_overdue
                  FROM purchase_invoices pi
                  JOIN suppliers s ON s.id = pi.supplier_id
                 WHERE pi.status = 'approved'
                   AND pi.deleted_at IS NULL
                   AND pi.doc_date <= ?
                   AND pi.total > pi.paid_amount
                   AND (CAST(? AS bigint) IS NULL OR pi.supplier_id = CAST(? AS bigint))
                 ORDER BY pi.due_date, pi.doc_no
            SQL,
            [$asOf, $asOf, $supplierId, $supplierId],
        );

        return $rows;
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
