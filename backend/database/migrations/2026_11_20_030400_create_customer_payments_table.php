<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment In (pembayaran customer) beserta alokasinya ke sales invoice.
 *
 * Approve membentuk jurnal Dr Kas atau Bank / Cr Piutang Usaha dan menaikkan
 * paid_amount invoice. Alokasi tidak boleh melebihi sisa tagihan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->date('doc_date');
            $table->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
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
            $table->index('customer_id');
        });

        Schema::create('customer_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_payment_id')
                ->constrained('customer_payments')
                ->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(
                ['customer_payment_id', 'sales_invoice_id'],
                'customer_payment_allocations_unique_invoice',
            );
        });

        DB::statement(
            'ALTER TABLE customer_payments ADD CONSTRAINT customer_payments_status_check '
            ."CHECK (status IN ('draft', 'approved', 'closed', 'cancelled'))"
        );
        DB::statement(
            'ALTER TABLE customer_payments ADD CONSTRAINT customer_payments_method_check '
            ."CHECK (method IN ('cash', 'bank'))"
        );
        DB::statement(
            'ALTER TABLE customer_payments ADD CONSTRAINT customer_payments_amount_check CHECK (amount > 0)'
        );
        DB::statement(
            'ALTER TABLE customer_payment_allocations ADD CONSTRAINT customer_payment_allocations_amount_check '
            .'CHECK (amount > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');
    }
};
