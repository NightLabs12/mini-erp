<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Membungkus hasil paginasi menjadi { data, meta, links }.
 *
 * Pembungkusan "data" dilakukan oleh ResourceResponse; kelas ini hanya
 * menyeragamkan bentuk meta dan links.
 */
class ApiCollection extends ResourceCollection
{
    /**
     * Jangan menebak resource dari nama kelas: ApiCollection bukan pasangan
     * dari ApiResource yang abstrak.
     *
     * @var class-string<JsonResource>
     */
    public $collects = JsonResource::class;

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
