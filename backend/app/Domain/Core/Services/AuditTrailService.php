<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Satu-satunya pintu penulisan audit_logs.
 *
 * Dipakai oleh trait Auditable untuk siklus hidup Eloquent dan oleh aksi
 * domain (approve, disapprove, close, period_closed) lewat record().
 */
final class AuditTrailService
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(Model $model, string $event, array $old = [], array $new = []): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => $this->actorId(),
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'event' => $event,
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
            'diff' => $this->diff($old, $new),
            'ip' => $this->requestValue('ip'),
            'user_agent' => $this->requestValue('user_agent'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{old: mixed, new: mixed}>|null
     */
    private function diff(array $old, array $new): ?array
    {
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);
        $diff = [];

        foreach ($keys as $key) {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;

            if ($before === $after) {
                continue;
            }

            $diff[(string) $key] = ['old' => $before, 'new' => $after];
        }

        return $diff === [] ? null : $diff;
    }

    private function requestValue(string $key): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        $value = match ($key) {
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            default => null,
        };

        return $value === null ? null : mb_substr($value, 0, 512);
    }

    /**
     * Auth::id() memakai guard default (web). Request API diautentikasi lewat
     * guard sanctum, sehingga pelaku diambil dari request lebih dulu.
     */
    private function actorId(): int|string|null
    {
        if (app()->bound('request')) {
            $user = request()->user();

            if ($user !== null) {
                return $user->getAuthIdentifier();
            }
        }

        return Auth::id();
    }
}
