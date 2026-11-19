<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Core\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('roles');

        return new UserResource($user);
    }
}
