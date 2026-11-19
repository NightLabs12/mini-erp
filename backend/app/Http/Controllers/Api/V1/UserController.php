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

        DB::transaction(function () use ($data, $user): void {
            $attributes = array_intersect_key($data, array_flip(['name', 'email']));

            if (array_key_exists('password', $data) && $data['password'] !== null) {
                $attributes['password'] = $data['password'];
            }

            $user->fill($attributes)->save();

            if (array_key_exists('roles', $data)) {
                $user->syncRoles($data['roles']);
            }
        });

        return new UserResource($user->fresh(['roles.permissions']));
    }

    public function destroy(User $user): Response
    {
        $this->authorize('delete', $user);

        $this->ensureAdminRemains($user, []);

        $user->delete();

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
}
