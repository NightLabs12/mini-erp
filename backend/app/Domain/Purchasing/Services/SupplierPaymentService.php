<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Services\AccountMapper;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Core\Services\AuditTrailService;
use App\Domain\Core\Services\DocumentSequenceService;
use App\Domain\Master\Models\Supplier;
use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Purchasing\Models\SupplierPaymentAllocation;
use App\Exceptions\ApiException;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Payment Out: pembayaran supplier dengan alokasi ke banyak invoice.
 *
 * Alokasi boleh parsial, tetapi tidak boleh melebihi sisa tagihan dan total
 * alokasi harus sama dengan amount pembayaran. Approve membentuk jurnal
 * Dr Hutang Usaha / Cr Kas atau Bank.
 */
final class SupplierPaymentService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly PeriodGuard $periods,
        private readonly JournalService $journals,
        private readonly AccountMapper $accounts,
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SupplierPayment
    {
        return DB::transaction(function () use ($data): SupplierPayment {
            $day = $this->day($data['doc_date']);
            $period = $this->periods->assertOpen($day);
            $supplierId = (int) $data['supplier_id'];
            Supplier::query()->findOrFail($supplierId);
            $method = $this->method($data['method'] ?? null);
            $account = $this->account($method, $data['account_id'] ?? null);
            $allocations = $this->normalizeAllocations($data['allocations'] ?? []);
            $amount = $this->resolveAmount($data['amount'] ?? null, $allocations);

            $this->assertAllocations($supplierId, $allocations, $amount);

            /** @var SupplierPayment $payment */
            $payment = SupplierPayment::query()->create([
                'doc_no' => $this->sequences->next('PAYOUT', $day),
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'supplier_id' => $supplierId,
                'method' => $method,
                'account_id' => $account->getKey(),
                'amount' => $amount,
                'status' => SupplierPayment::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->actorId(),
            ]);

            $this->syncAllocations($payment, $allocations);

            return $this->load($payment);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SupplierPayment $payment, array $data): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $data): SupplierPayment {
            $locked = $this->lock($payment);

            if ($locked->status !== SupplierPayment::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya supplier payment draft yang boleh diubah.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $day = array_key_exists('doc_date', $data)
                ? $this->day($data['doc_date'])
                : $this->day($locked->doc_date);
            $period = $this->periods->assertOpen($day);
            $supplierId = (int) ($data['supplier_id'] ?? $locked->supplier_id);
            Supplier::query()->findOrFail($supplierId);
            $method = array_key_exists('method', $data)
                ? $this->method($data['method'])
                : (string) $locked->method;
            $account = array_key_exists('account_id', $data) || array_key_exists('method', $data)
                ? $this->account($method, $data['account_id'] ?? null)
                : Account::query()->findOrFail((int) $locked->account_id);

            $allocations = array_key_exists('allocations', $data)
                ? $this->normalizeAllocations($data['allocations'])
                : $this->existingAllocations($locked);
            $amount = $this->resolveAmount($data['amount'] ?? null, $allocations);

            $this->assertAllocations($supplierId, $allocations, $amount);

            $locked->forceFill([
                'doc_date' => $day->toDateString(),
                'period_id' => $period->getKey(),
                'supplier_id' => $supplierId,
                'method' => $method,
                'account_id' => $account->getKey(),
                'amount' => $amount,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ])->save();

            if (array_key_exists('allocations', $data)) {
                $this->syncAllocations($locked, $allocations);
            }

            return $this->load($locked);
        }, 5);
    }

    public function deleteDraft(SupplierPayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = $this->lock($payment);

            if ($locked->status !== SupplierPayment::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Hanya supplier payment draft yang boleh dihapus.',
                    'DOCUMENT_NOT_DRAFT',
                );
            }

            $locked->allocations()->delete();
            $locked->delete();
        }, 5);
    }

    public function approve(SupplierPayment $payment): SupplierPayment
    {
        return DB::transaction(function () use ($payment): SupplierPayment {
            $locked = $this->lock($payment);

            if ($locked->status === SupplierPayment::STATUS_APPROVED) {
                return $this->load($locked);
            }

            if ($locked->status !== SupplierPayment::STATUS_DRAFT) {
                throw ApiException::conflict(
                    'Supplier payment tidak dapat disetujui dari status ini.',
                    'DOCUMENT_STATE_INVALID',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            $allocations = $this->existingAllocations($locked);
            $amount = $this->resolveAmount((string) $locked->amount, $allocations);
            $this->assertAllocations((int) $locked->supplier_id, $allocations, $amount);

            $invoiceIds = array_map(static fn (array $row): int => $row['purchase_invoice_id'], $allocations);

            /** @var Collection<int, PurchaseInvoice> $invoices */
            $invoices = PurchaseInvoice::query()
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (PurchaseInvoice $invoice): int => (int) $invoice->getKey());

            $this->assertAllocationsAgainstLockedInvoices($locked, $allocations, $invoices);

            $journal = $this->postPaymentJournal($locked, $day);

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get($allocation['purchase_invoice_id']);

                if (! $invoice instanceof PurchaseInvoice) {
                    continue;
                }

                $invoice->forceFill([
                    'paid_amount' => Decimal::add(
                        (string) $invoice->paid_amount,
                        $allocation['amount'],
                        Decimal::MONEY_SCALE,
                    ),
                ])->save();
            }

            $locked->forceFill([
                'status' => SupplierPayment::STATUS_APPROVED,
                'journal_id' => $journal?->getKey(),
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
            ])->save();

            $this->audit->record($locked, 'approved', ['status' => SupplierPayment::STATUS_DRAFT], [
                'status' => SupplierPayment::STATUS_APPROVED,
                'doc_no' => $locked->doc_no,
                'journal_id' => $locked->journal_id,
            ]);

            return $this->load($locked);
        }, 5);
    }

    public function disapprove(SupplierPayment $payment): SupplierPayment
    {
        return DB::transaction(function () use ($payment): SupplierPayment {
            $locked = $this->lock($payment);

            if ($locked->status !== SupplierPayment::STATUS_APPROVED) {
                throw ApiException::conflict(
                    'Hanya supplier payment approved yang dapat dibatalkan.',
                    'DOCUMENT_NOT_APPROVED',
                );
            }

            $day = $this->day($locked->doc_date);
            $this->periods->assertOpen($day, true);

            $allocations = $this->existingAllocations($locked);
            $invoiceIds = array_map(static fn (array $row): int => $row['purchase_invoice_id'], $allocations);

            /** @var Collection<int, PurchaseInvoice> $invoices */
            $invoices = PurchaseInvoice::query()
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (PurchaseInvoice $invoice): int => (int) $invoice->getKey());

            $journal = $locked->journal_id !== null
                ? Journal::query()->find($locked->journal_id)
                : null;

            if ($journal instanceof Journal && $journal->status === Journal::STATUS_POSTED) {
                $this->journals->reverse($journal);
            }

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get($allocation['purchase_invoice_id']);

                if (! $invoice instanceof PurchaseInvoice) {
                    continue;
                }

                $paid = Decimal::sub(
                    (string) $invoice->paid_amount,
                    $allocation['amount'],
                    Decimal::MONEY_SCALE,
                );

                $invoice->forceFill([
                    'paid_amount' => Decimal::compare($paid, '0', Decimal::MONEY_SCALE) < 0
                        ? '0.00'
                        : $paid,
                ])->save();
            }

            $locked->forceFill([
                'status' => SupplierPayment::STATUS_DRAFT,
                'journal_id' => null,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $this->audit->record($locked, 'disapproved', ['status' => SupplierPayment::STATUS_APPROVED], [
                'status' => SupplierPayment::STATUS_DRAFT,
                'doc_no' => $locked->doc_no,
            ]);

            return $this->load($locked);
        }, 5);
    }

    /**
     * @param  list<array{purchase_invoice_id: int, amount: string}>  $allocations
     */
    private function syncAllocations(SupplierPayment $payment, array $allocations): void
    {
        $payment->allocations()->delete();

        foreach ($allocations as $allocation) {
            $payment->allocations()->create([
                'purchase_invoice_id' => $allocation['purchase_invoice_id'],
                'amount' => $allocation['amount'],
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $allocations
     * @return list<array{purchase_invoice_id: int, amount: string}>
     */
    private function normalizeAllocations(array $allocations): array
    {
        $normalized = [];

        foreach ($allocations as $row) {
            if (! is_array($row)) {
                continue;
            }

            $invoiceId = (int) ($row['purchase_invoice_id'] ?? 0);
            $amount = Decimal::round((string) ($row['amount'] ?? '0'), Decimal::MONEY_SCALE);

            if ($invoiceId <= 0 || Decimal::compare($amount, '0', Decimal::MONEY_SCALE) <= 0) {
                throw ApiException::unprocessable(
                    'Alokasi pembayaran tidak valid.',
                    'PAYMENT_ALLOCATION_INVALID',
                    ['allocations' => ['Invoice dan nominal alokasi wajib valid.']],
                );
            }

            $normalized[] = ['purchase_invoice_id' => $invoiceId, 'amount' => $amount];
        }

        if ($normalized === []) {
            throw ApiException::unprocessable(
                'Pembayaran minimal memiliki satu alokasi invoice.',
                'PAYMENT_ALLOCATION_REQUIRED',
                ['allocations' => ['Tambahkan minimal satu invoice.']],
            );
        }

        $ids = array_map(static fn (array $row): int => $row['purchase_invoice_id'], $normalized);

        if (count($ids) !== count(array_unique($ids))) {
            throw ApiException::unprocessable(
                'Satu invoice tidak boleh dialokasikan lebih dari sekali.',
                'PAYMENT_ALLOCATION_DUPLICATE',
                ['allocations' => ['Invoice duplikat.']],
            );
        }

        return $normalized;
    }

    /**
     * @param  list<array{purchase_invoice_id: int, amount: string}>  $allocations
     */
    private function resolveAmount(mixed $value, array $allocations): string
    {
        $sum = '0.00';

        foreach ($allocations as $allocation) {
            $sum = Decimal::add($sum, $allocation['amount'], Decimal::MONEY_SCALE);
        }

        if ($value === null || $value === '') {
            return $sum;
        }

        $amount = Decimal::round((string) $value, Decimal::MONEY_SCALE);

        if (Decimal::compare($amount, $sum, Decimal::MONEY_SCALE) !== 0) {
            throw ApiException::unprocessable(
                "Total pembayaran {$amount} tidak sama dengan total alokasi {$sum}.",
                'PAYMENT_AMOUNT_MISMATCH',
                ['amount' => ["Total alokasi harus {$sum}."]],
            );
        }

        return $amount;
    }

    /**
     * @param  list<array{purchase_invoice_id: int, amount: string}>  $allocations
     */
    private function assertAllocations(int $supplierId, array $allocations, string $amount): void
    {
        $ids = array_map(static fn (array $row): int => $row['purchase_invoice_id'], $allocations);

        /** @var Collection<int, PurchaseInvoice> $invoices */
        $invoices = PurchaseInvoice::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(static fn (PurchaseInvoice $invoice): int => (int) $invoice->getKey());

        $this->assertAllocationsAgainstLockedInvoices(
            new SupplierPayment(['supplier_id' => $supplierId, 'amount' => $amount]),
            $allocations,
            $invoices,
        );
    }

    /**
     * @param  list<array{purchase_invoice_id: int, amount: string}>  $allocations
     * @param  Collection<int, PurchaseInvoice>  $invoices
     */
    private function assertAllocationsAgainstLockedInvoices(
        SupplierPayment $payment,
        array $allocations,
        Collection $invoices,
    ): void {
        foreach ($allocations as $allocation) {
            $invoice = $invoices->get($allocation['purchase_invoice_id']);

            if (! $invoice instanceof PurchaseInvoice) {
                throw ApiException::unprocessable(
                    'Invoice alokasi tidak ditemukan.',
                    'PAYMENT_INVOICE_NOT_FOUND',
                    ['allocations' => ['Invoice tidak ditemukan.']],
                );
            }

            if ($invoice->status !== PurchaseInvoice::STATUS_APPROVED) {
                throw ApiException::conflict(
                    "Invoice {$invoice->doc_no} belum approved.",
                    'PAYMENT_INVOICE_NOT_APPROVED',
                );
            }

            if ((int) $invoice->supplier_id !== (int) $payment->supplier_id) {
                throw ApiException::unprocessable(
                    'Invoice bukan milik supplier pembayaran ini.',
                    'PAYMENT_SUPPLIER_MISMATCH',
                );
            }

            $remaining = Decimal::sub(
                (string) $invoice->total,
                (string) $invoice->paid_amount,
                Decimal::MONEY_SCALE,
            );

            if (Decimal::compare($allocation['amount'], $remaining, Decimal::MONEY_SCALE) > 0) {
                throw ApiException::unprocessable(
                    "Alokasi melebihi sisa tagihan {$invoice->doc_no}: sisa {$remaining}, "
                    ."diminta {$allocation['amount']}.",
                    'PAYMENT_EXCEEDS_REMAINING',
                    ['allocations' => ["Sisa tagihan {$remaining}."]],
                );
            }
        }
    }

    /**
     * @return list<array{purchase_invoice_id: int, amount: string}>
     */
    private function existingAllocations(SupplierPayment $payment): array
    {
        /** @var Collection<int, SupplierPaymentAllocation> $rows */
        $rows = $payment->allocations()->orderBy('purchase_invoice_id')->get();

        return $rows->map(static fn (SupplierPaymentAllocation $row): array => [
            'purchase_invoice_id' => (int) $row->purchase_invoice_id,
            'amount' => Decimal::round((string) $row->amount, Decimal::MONEY_SCALE),
        ])->all();
    }

    private function postPaymentJournal(SupplierPayment $payment, CarbonImmutable $day): ?Journal
    {
        $amount = Decimal::round((string) $payment->amount, Decimal::MONEY_SCALE);

        if (Decimal::compare($amount, '0', Decimal::MONEY_SCALE) <= 0) {
            return null;
        }

        return $this->journals->post([
            [
                'account_id' => (int) $this->accounts->accountFor(AccountMapper::ACCOUNTS_PAYABLE)->getKey(),
                'debit' => $amount,
            ],
            [
                'account_id' => (int) $payment->account_id,
                'credit' => $amount,
            ],
        ], ['type' => 'supplier_payment', 'id' => (int) $payment->getKey()], $day, "Pembayaran supplier {$payment->doc_no}");
    }

    private function account(string $method, mixed $accountId): Account
    {
        if ($accountId !== null && $accountId !== '') {
            /** @var Account $account */
            $account = Account::query()->findOrFail((int) $accountId);

            return $account;
        }

        return $this->accounts->accountFor(
            $method === SupplierPayment::METHOD_BANK
                ? AccountMapper::BANK_DEFAULT
                : AccountMapper::CASH_DEFAULT,
        );
    }

    private function method(mixed $value): string
    {
        $method = strtolower(trim((string) $value));

        if (! in_array($method, [SupplierPayment::METHOD_CASH, SupplierPayment::METHOD_BANK], true)) {
            throw ApiException::unprocessable(
                'Metode pembayaran harus cash atau bank.',
                'PAYMENT_METHOD_INVALID',
                ['method' => ['Gunakan cash atau bank.']],
            );
        }

        return $method;
    }

    private function lock(SupplierPayment $payment): SupplierPayment
    {
        /** @var SupplierPayment $locked */
        $locked = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->getKey());

        return $locked;
    }

    private function load(SupplierPayment $payment): SupplierPayment
    {
        return $payment->refresh()->load([
            'supplier',
            'account',
            'journal',
            'allocations.invoice',
        ]);
    }

    private function day(CarbonInterface|string $date): CarbonImmutable
    {
        return $date instanceof CarbonInterface
            ? CarbonImmutable::parse($date->toDateString())
            : CarbonImmutable::parse($date);
    }

    private function actorId(): ?int
    {
        $user = app()->bound('request') ? request()->user() : null;
        $id = $user?->getAuthIdentifier() ?? Auth::id();

        return $id === null ? null : (int) $id;
    }
}
