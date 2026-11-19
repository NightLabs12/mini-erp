<?php

declare(strict_types=1);

namespace App\Http\Resources;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dasar seluruh API Resource agar bentuk respons konsisten.
 *
 * Setiap resource konkret mengimplementasikan data() dengan field yang
 * eksplisit, sehingga kolom sensitif tidak pernah bocor secara tidak sengaja.
 */
abstract class ApiResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    abstract public function data(Request $request): array;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->data($request);
    }

    /** @return array<string, string> */
    protected function timestamps(): array
    {
        $model = $this->resource;

        if (! $model instanceof Model) {
            return [];
        }

        $createdAt = $model->getAttribute('created_at');
        $updatedAt = $model->getAttribute('updated_at');

        return array_filter([
            'created_at' => $createdAt instanceof DateTimeInterface ? $createdAt->format(DATE_ATOM) : null,
            'updated_at' => $updatedAt instanceof DateTimeInterface ? $updatedAt->format(DATE_ATOM) : null,
        ], static fn (?string $value): bool => $value !== null);
    }
}
