<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Core\Models\User;
use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\InteractsWithAuditTrail;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\UserResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class UserController extends Controller
{
    use InteractsWithAuditTrail;

    public function index(Request $request): ApiCollection
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()->with('roles.permissions');

        $role = $request->input('filter.role');

        if (is_string($role) && $role !== '') {
            $query->whereHas('roles', static fn (Builder $builder): Builder => $builder->where('name', $role));
        }

        $paginator = $this->paginateListQuery($query, $request, [
            'sortable' => ['name', 'email', 'created_at'],
            'filterable' => ['name', 'email', 'created_at'],
            'searchable' => ['name', 'email'],
            'default_sort' => 'name',
        ]);

        return new ApiCollection($paginator, UserResource::class);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $user->syncRoles($data['roles'] ?? []);

            return $user;
        });

        return (new UserResource($user->load('roles.permissions')))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user->load('roles.permissions'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $data = $request->validated();

        if (array_key_exists('roles', $data)) {
            $this->ensureAdminRemains($user, $data['roles']);
        }

        if (($data['is_active'] ?? true) === false) {
            $this->ensureAdminRemainsActive($user);
        }

        $passwordChanged = array_key_exists('password', $data) && $data['password'] !== null;
        $deactivated = array_key_exists('is_active', $data) && $data['is_active'] === false;

        DB::transaction(function () use ($data, $user, $passwordChanged, $deactivated): void {
            $attributes = array_intersect_key($data, array_flip(['name', 'email', 'is_active']));

            if ($passwordChanged) {
                $attributes['password'] = $data['password'];
            }

            $user->fill($attributes)->save();

            if (array_key_exists('roles', $data)) {
                $user->syncRoles($data['roles']);
            }

            // Token lama tidak boleh tetap berlaku setelah password berubah
            // atau akun dinonaktifkan. Token yang sedang dipakai dipertahankan
            // hanya bila pelaku mengubah password akunnya sendiri.
            if ($deactivated) {
                $user->revokeTokens();
            } elseif ($passwordChanged) {
                $keepCurrent = $this->currentUserId() === $user->getKey();

                $user->revokeTokens($keepCurrent ? $this->currentTokenId() : null);
            }
        });

        return new UserResource($user->fresh(['roles.permissions']));
    }

    public function destroy(User $user): Response
    {
        $this->authorize('delete', $user);

        $this->ensureAdminRemains($user, []);

        DB::transaction(function () use ($user): void {
            $user->revokeTokens();
            $user->delete();
        });

        return response()->noContent();
    }

    public function audit(Request $request, User $user): ApiCollection
    {
        $this->authorize('view', $user);

        return $this->auditTrailFor($user, $request);
    }

    /**
     * @param  list<string>  $roles
     */
    private function ensureAdminRemains(User $user, array $roles): void
    {
        if (! $user->hasRole('admin') || in_array('admin', $roles, true)) {
            return;
        }

        $otherAdmins = User::query()
            ->role('admin')
            ->whereKeyNot($user->getKey())
            ->count();

        if ($otherAdmins === 0) {
            throw ApiException::conflict(
                'Role admin tidak dapat dicabut dari pengguna terakhir yang memilikinya.',
                'LAST_ADMIN',
            );
        }
    }

    private function ensureAdminRemainsActive(User $user): void
    {
        if (! $user->hasRole('admin') || ! $user->is_active) {
            return;
        }

        $otherActiveAdmins = User::query()
            ->role('admin')
            ->whereKeyNot($user->getKey())
            ->where('is_active', true)
            ->count();

        if ($otherActiveAdmins === 0) {
            throw ApiException::conflict(
                'Admin aktif terakhir tidak dapat dinonaktifkan.',
                'LAST_ADMIN',
            );
        }
    }

    private function currentUserId(): int|string|null
    {
        return request()->user()?->getAuthIdentifier();
    }

    private function currentTokenId(): ?int
    {
        $token = request()->user()?->currentAccessToken();

        return $token instanceof \Laravel\Sanctum\PersonalAccessToken ? (int) $token->getKey() : null;
    }
}
