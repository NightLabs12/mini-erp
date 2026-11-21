<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index pendukung laporan keuangan dan persediaan.
 *
 * - journals (status, journal_date): filter jurnal posted/reversed per rentang.
 * - journal_lines (journal_date, id): saldo awal dan slice periode tanpa
 *   bergantung pada account_id.
 * - stock_ledger (item_id, movement_date, id): Kartu Stok lintas gudang.
 * - invoice (status, doc_date, ...): AP/AR outstanding dan aging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->index(['status', 'journal_date'], 'journals_status_date_idx');
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->index(['journal_date', 'id'], 'journal_lines_date_idx');
        });

        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->index(['item_id', 'movement_date', 'id'], 'stock_ledger_item_date_idx');
        });

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->index(['status', 'doc_date', 'supplier_id'], 'purchase_invoices_status_date_idx');
            $table->index(['status', 'due_date'], 'purchase_invoices_status_due_idx');
        });

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->index(['status', 'doc_date', 'customer_id'], 'sales_invoices_status_date_idx');
            $table->index(['status', 'due_date'], 'sales_invoices_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->dropIndex('journals_status_date_idx');
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropIndex('journal_lines_date_idx');
        });

        Schema::table('stock_ledger', function (Blueprint $table): void {
            $table->dropIndex('stock_ledger_item_date_idx');
        });

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->dropIndex('purchase_invoices_status_date_idx');
            $table->dropIndex('purchase_invoices_status_due_idx');
        });

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropIndex('sales_invoices_status_date_idx');
            $table->dropIndex('sales_invoices_status_due_idx');
        });
    }
};
