<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Core\Models\User;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw new ApiException('Email atau password salah.', 'INVALID_CREDENTIALS', 401);
        }

        // Status aktif baru diungkap setelah password terbukti benar, sehingga
        // penyerang tanpa password tidak dapat memetakan akun yang ada.
        if (! $user->isActive()) {
            throw new ApiException('Akun Anda dinonaktifkan. Hubungi administrator.', 'USER_INACTIVE', 403);
        }

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 1440));
        $token = $user->createToken($data['device_name'] ?? 'api', ['*'], $expiresAt);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => (new UserResource($user))->resolve(),
            ],
        ]);
    }
}
