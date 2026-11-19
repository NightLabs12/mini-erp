<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Core\Models\AuditLog;
use Illuminate\Http\Request;

final class AuditLogResource extends ApiResource
{
    private readonly AuditLog $log;

    public function __construct(AuditLog $log)
    {
        parent::__construct($log);

        $this->log = $log;
    }

    /** @return array<string, mixed> */
    public function data(Request $request): array
    {
        return [
            'id' => $this->log->getKey(),
            'event' => $this->log->event,
            'auditable_type' => $this->log->auditable_type,
            'auditable_id' => $this->log->auditable_id,
            'user' => $this->log->relationLoaded('user') && $this->log->user !== null
                ? [
                    'id' => $this->log->user->getKey(),
                    'name' => $this->log->user->name,
                    'email' => $this->log->user->email,
                ]
                : null,
            'old_values' => $this->log->old_values ?? [],
            'new_values' => $this->log->new_values ?? [],
            'diff' => $this->log->diff ?? [],
            'ip' => $this->log->ip,
            'created_at' => $this->log->created_at?->toIso8601String(),
        ];
    }
}
