<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\Journal;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\JournalService;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\JournalRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\JournalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class JournalController extends Controller
{
    use InteractsWithAuditTrail;

    public function __construct(private readonly JournalService $journals) {}

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', Journal::class);

        $paginator = $this->paginateListQuery(Journal::query()->with(['period']), $request, [
            'sortable' => ['journal_no', 'journal_date', 'status', 'total_debit', 'total_credit', 'created_at'],
            'filterable' => ['journal_no', 'journal_date', 'period_id', 'source_type', 'source_id', 'status', 'created_at'],
            'searchable' => ['journal_no', 'description'],
            'default_sort' => '-journal_date',
        ]);

        return new ApiCollection($paginator, JournalResource::class);
    }

    public function store(JournalRequest $request): JsonResponse
    {
        $this->authorize('create', Journal::class);

        $data = $request->validated();

        $journal = $this->journals->createDraft(
            $data['lines'],
            $data['journal_date'],
            $data['description'] ?? null,
            ['type' => 'manual'],
        );

        return (new JournalResource($journal))->response()->setStatusCode(201);
    }

    public function show(Journal $journal): JournalResource
    {
        $this->authorize('view', $journal);

        return new JournalResource($this->loadJournal($journal));
    }

    public function update(JournalRequest $request, Journal $journal): JournalResource
    {
        $this->authorize('update', $journal);

        $data = $request->validated();

        $updated = $this->journals->updateDraft(
            $journal,
            $data['lines'] ?? $this->linesPayload($journal),
            $data['journal_date'] ?? null,
            array_key_exists('description', $data) ? $data['description'] : $journal->description,
        );

        return new JournalResource($updated);
    }

    public function destroy(Journal $journal): Response
    {
        $this->authorize('delete', $journal);

        $this->journals->deleteDraft($journal);

        return response()->noContent();
    }

    public function approve(Journal $journal): JournalResource
    {
        $this->authorize('approve', $journal);

        return new JournalResource($this->journals->approve($journal));
    }

    public function reverse(Journal $journal): JsonResponse
    {
        $this->authorize('reverse', $journal);

        $reversal = $this->journals->reverse($journal);

        return (new JournalResource($reversal))->response()->setStatusCode(201);
    }

    public function audit(Request $request, Journal $journal): ApiCollection
    {
        $this->authorize('view', $journal);

        return $this->auditTrailFor($journal, $request);
    }

    private function loadJournal(Journal $journal): Journal
    {
        return $journal->refresh()->load(['lines.account', 'period']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linesPayload(Journal $journal): array
    {
        return $journal->lines()
            ->get()
            ->map(static fn (JournalLine $line): array => [
                'account_id' => $line->account_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'description' => $line->description,
                'partner_type' => $line->partner_type,
                'partner_id' => $line->partner_id,
            ])
            ->all();
    }
}
