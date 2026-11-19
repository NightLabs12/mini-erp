<?php

declare(strict_types=1);

namespace App\Domain\Core\Traits;

use App\Domain\Core\Services\AuditTrailService;

/**
 * Mencatat created, updated, dan deleted ke audit_logs.
 *
 * Model pemakai wajib memakai SoftDeletes agar event deleted dapat dibedakan
 * dari force_deleted. Atribut sensitif dikecualikan lewat
 * auditExcludedAttributes(). Event domain lain (approved, closed,
 * period_closed) dipanggil langsung ke AuditTrailService karena bukan bagian
 * dari siklus hidup Eloquent.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            app(AuditTrailService::class)->record(
                $model,
                'created',
                [],
                $model->auditSnapshot($model->getAttributes()),
            );
        });

        static::updated(function (self $model): void {
            $excluded = [...$model->auditExcludedAttributes(), 'updated_at'];
            $changed = array_values(array_diff(array_keys($model->getChanges()), $excluded));

            if ($changed === []) {
                return;
            }

            $old = [];
            $new = [];

            foreach ($changed as $attribute) {
                $old[$attribute] = $model->getOriginal($attribute);
                $new[$attribute] = $model->getAttribute($attribute);
            }

            app(AuditTrailService::class)->record($model, 'updated', $old, $new);
        });

        static::deleted(function (self $model): void {
            app(AuditTrailService::class)->record(
                $model,
                $model->isForceDeleting() ? 'force_deleted' : 'deleted',
                $model->auditSnapshot($model->getOriginal()),
                [],
            );
        });
    }

    /**
     * Atribut yang tidak pernah masuk audit trail.
     *
     * @return list<string>
     */
    protected function auditExcludedAttributes(): array
    {
        return ['password', 'remember_token', 'updated_at'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditSnapshot(array $attributes): array
    {
        $excluded = $this->auditExcludedAttributes();

        return array_filter(
            $attributes,
            static fn (string $key): bool => ! in_array($key, $excluded, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
