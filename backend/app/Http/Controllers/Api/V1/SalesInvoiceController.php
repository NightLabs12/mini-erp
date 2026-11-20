<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Core\Services\SettingService;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Services\SalesInvoiceService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesInvoiceRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\SalesInvoiceResource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class SalesInvoiceController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(
        private readonly SalesInvoiceService $invoices,
        private readonly SettingService $settings,
    ) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', SalesInvoice::class);

        $paginator = $this->paginateListQuery(
            SalesInvoice::query()->with(['customer', 'items.item', 'items.unit']),
            $request,
            [
                'sortable' => ['doc_no', 'doc_date', 'due_date', 'status', 'mode', 'total', 'paid_amount', 'created_at'],
                'filterable' => ['doc_no', 'doc_date', 'due_date', 'status', 'mode', 'customer_id', 'created_at'],
                'searchable' => ['doc_no', 'notes'],
                'default_sort' => '-doc_date',
            ],
        );

        return new ApiCollection($paginator, SalesInvoiceResource::class);
    }

    public function store(SalesInvoiceRequest $request): JsonResponse
    {
        $this->authorize('create', SalesInvoice::class);

        $invoice = $this->invoices->create($request->validated());

        return (new SalesInvoiceResource($invoice))->response()->setStatusCode(201);
    }

    public function show(SalesInvoice $salesInvoice): SalesInvoiceResource
    {
        $this->authorize('view', $salesInvoice);

        return new SalesInvoiceResource($this->load($salesInvoice));
    }

    public function update(SalesInvoiceRequest $request, SalesInvoice $salesInvoice): SalesInvoiceResource
    {
        $this->authorize('update', $salesInvoice);

        return new SalesInvoiceResource($this->invoices->update($salesInvoice, $request->validated()));
    }

    public function destroy(SalesInvoice $salesInvoice): Response
    {
        $this->authorize('delete', $salesInvoice);

        $this->invoices->deleteDraft($salesInvoice);

        return response()->noContent();
    }

    public function approve(SalesInvoice $salesInvoice): SalesInvoiceResource
    {
        $this->authorize('approve', $salesInvoice);

        return new SalesInvoiceResource($this->invoices->approve($salesInvoice));
    }

    public function disapprove(SalesInvoice $salesInvoice): SalesInvoiceResource
    {
        $this->authorize('disapprove', $salesInvoice);

        return new SalesInvoiceResource($this->invoices->disapprove($salesInvoice));
    }

    public function print(SalesInvoice $salesInvoice): SymfonyResponse
    {
        $this->authorize('print', $salesInvoice);

        $invoice = $this->load($salesInvoice);

        $pdf = Pdf::loadView('sales.sales-invoice', [
            'invoice' => $invoice,
            'company' => $this->settings->get('company_name', 'PT Contoh Niaga Makmur'),
        ])->setPaper('a4');

        return $pdf->download(str_replace('/', '-', $invoice->doc_no).'.pdf');
    }

    public function audit(Request $request, SalesInvoice $salesInvoice): ApiCollection
    {
        $this->authorize('view', $salesInvoice);

        return $this->auditTrailFor($salesInvoice, $request);
    }

    private function load(SalesInvoice $invoice): SalesInvoice
    {
        return $invoice->refresh()->load([
            'customer',
            'journal',
            'items.item',
            'items.unit',
            'items.deliveryOrderItem',
        ]);
    }
}
