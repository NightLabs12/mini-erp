<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Membungkus hasil paginasi menjadi { data, meta, links }.
 *
 * $collects menentukan API Resource yang dipakai untuk setiap baris, sehingga
 * kolom sensitif tidak pernah ikut terkirim.
 */
class ApiCollection extends ResourceCollection
{
    /**
     * @param  mixed  $resource
     * @param  class-string<JsonResource>  $collects
     */
    public function __construct($resource, string $collects = JsonResource::class)
    {
        $this->collects = $collects;

        parent::__construct($resource);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation($request, $paginated, $default): array
    {
        return [
            'meta' => [
                'current_page' => $paginated['current_page'],
                'per_page' => $paginated['per_page'],
                'last_page' => $paginated['last_page'],
                'total' => $paginated['total'],
                'from' => $paginated['from'],
                'to' => $paginated['to'],
            ],
            'links' => [
                'first' => $paginated['first_page_url'],
                'prev' => $paginated['prev_page_url'],
                'next' => $paginated['next_page_url'],
                'last' => $paginated['last_page_url'],
            ],
        ];
    }
}
