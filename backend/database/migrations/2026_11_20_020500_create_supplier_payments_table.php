<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment Out (pembayaran supplier) beserta alokasinya ke purchase invoice.
 *
 * Approve membentuk jurnal Dr Hutang Usaha / Cr Kas atau Bank dan menaikkan
 * paid_amount invoice. Alokasi tidak boleh melebihi sisa tagihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->date('doc_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('method', 10);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('status', 10)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('doc_date');
            $table->index('status');
            $table->index('supplier_id');
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_payment_id')
                ->constrained('supplier_payments')
                ->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(
                ['supplier_payment_id', 'purchase_invoice_id'],
                'supplier_payment_allocations_unique_invoice',
            );
        });

        DB::statement(
            'ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_status_check '
            ."CHECK (status IN ('draft', 'approved', 'closed', 'cancelled'))"
        );
        DB::statement(
            'ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_method_check '
            ."CHECK (method IN ('cash', 'bank'))"
        );
        DB::statement(
            'ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_amount_check CHECK (amount > 0)'
        );
        DB::statement(
            'ALTER TABLE supplier_payment_allocations ADD CONSTRAINT supplier_payment_allocations_amount_check '
            .'CHECK (amount > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
    }
};
