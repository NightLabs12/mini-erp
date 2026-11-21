<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\Account;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\AccountRequest;
use App\Http\Resources\AccountResource;
use App\Http\Resources\ApiCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRUD Chart of Accounts.
 *
 * `level` dihitung dari parent, dan akun yang masih dipakai (anak, kategori
 * item, account_settings, atau baris jurnal) tidak boleh dihapus.
 */
final class AccountController extends Controller
{
    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', Account::class);

        $paginator = $this->paginateListQuery(Account::query(), $request, [
            'sortable' => ['code', 'name', 'type', 'level', 'is_postable', 'created_at'],
            'filterable' => ['code', 'name', 'type', 'normal_balance', 'parent_id', 'is_postable', 'is_active'],
            'searchable' => ['code', 'name'],
            'default_sort' => 'code',
            'per_page' => 50,
        ]);

        return new ApiCollection($paginator, AccountResource::class);
    }

    public function store(AccountRequest $request): JsonResponse
    {
        $this->authorize('create', Account::class);

        $account = DB::transaction(function () use ($request): Account {
            $data = $request->validated();

            /** @var Account $account */
            $account = Account::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
                'normal_balance' => $data['normal_balance'],
                'parent_id' => $data['parent_id'] ?? null,
                'level' => $this->levelFor($data['parent_id'] ?? null),
                'is_postable' => $data['is_postable'] ?? true,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $account;
        });

        return (new AccountResource($account->fresh() ?? $account))->response()->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        $this->authorize('view', $account);

        return new AccountResource($account);
    }

    public function update(AccountRequest $request, Account $account): AccountResource
    {
        $this->authorize('update', $account);

        DB::transaction(function () use ($request, $account): void {
            $data = $request->validated();
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $account->parent_id;

            if ($parentId !== null) {
                $this->assertNotDescendant($account, (int) $parentId);
            }

            $account->fill([
                'code' => $data['code'] ?? $account->code,
                'name' => $data['name'] ?? $account->name,
                'type' => $data['type'] ?? $account->type,
                'normal_balance' => $data['normal_balance'] ?? $account->normal_balance,
                'parent_id' => $parentId,
                'level' => $this->levelFor($parentId),
                'is_postable' => $data['is_postable'] ?? $account->is_postable,
                'is_active' => $data['is_active'] ?? $account->is_active,
            ])->save();

            $this->refreshDescendantLevels($account);
        });

        return new AccountResource($account->fresh() ?? $account);
    }

    public function destroy(Account $account): Response
    {
        $this->authorize('delete', $account);

        if ($account->children()->exists()) {
            throw ApiException::conflict(
                'Akun masih memiliki akun anak.',
                'ACCOUNT_IN_USE',
                ['references' => ['accounts.children']],
            );
        }

        $references = $this->references($account);

        if ($references !== []) {
            throw ApiException::conflict(
                'Akun masih dipakai: '.implode(', ', $references).'.',
                'ACCOUNT_IN_USE',
                ['references' => $references],
            );
        }

        $account->delete();

        return response()->noContent();
    }

    private function levelFor(mixed $parentId): int
    {
        if ($parentId === null || $parentId === '') {
            return 1;
        }

        /** @var Account $parent */
        $parent = Account::query()->findOrFail((int) $parentId);

        return $parent->level + 1;
    }

    /**
     * Mencegah siklus: parent baru tidak boleh akun itu sendiri atau turunannya.
     */
    private function assertNotDescendant(Account $account, int $parentId): void
    {
        $current = Account::query()->find($parentId);
        $depth = 0;

        while ($current instanceof Account && $depth < 100) {
            if ((int) $current->getKey() === (int) $account->getKey()) {
                throw ApiException::unprocessable(
                    'Akun induk tidak boleh akun itu sendiri atau turunannya.',
                    'ACCOUNT_PARENT_CYCLE',
                    ['parent_id' => ['Pilih akun induk lain.']],
                );
            }

            $current = $current->parent_id === null
                ? null
                : Account::query()->find($current->parent_id);
            $depth++;
        }
    }

    /**
     * Menjaga level seluruh turunan tetap konsisten setelah parent berubah.
     */
    private function refreshDescendantLevels(Account $account): void
    {
        /** @var list<Account> $children */
        $children = Account::query()->where('parent_id', $account->getKey())->get();

        foreach ($children as $child) {
            $child->forceFill(['level' => $account->level + 1])->save();
            $this->refreshDescendantLevels($child);
        }
    }

    /**
     * @return list<string>
     */
    private function references(Account $account): array
    {
        $references = [];
        $id = (int) $account->getKey();

        $checks = [
            ['item_categories', 'inventory_account_id'],
            ['item_categories', 'cogs_account_id'],
            ['item_categories', 'sales_account_id'],
            ['account_settings', 'account_id'],
            ['journal_lines', 'account_id'],
        ];

        foreach ($checks as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::table($table)->where($column, $id)->exists()) {
                $references[] = "{$table}.{$column}";
            }
        }

        return $references;
    }
}
