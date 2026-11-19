<?php

declare(strict_types=1);

use App\Domain\Core\Models\User;
use App\Http\Resources\ApiCollection;
use App\Support\Api\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    User::factory()->createMany([
        ['name' => 'Andi Wijaya', 'email' => 'andi@demo.test'],
        ['name' => 'Budi Santoso', 'email' => 'budi@demo.test'],
        ['name' => 'Citra Lestari', 'email' => 'citra@demo.test'],
    ]);
});

it('mencari lintas kolom yang diizinkan', function (): void {
    $request = Request::create('/?search=budi');
    $result = (new ListQuery(searchable: ['name', 'email']))->apply(User::query(), $request)->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()?->name)->toBe('Budi Santoso');
});

it('mengabaikan karakter wildcard pada pencarian', function (): void {
    $request = Request::create('/?search=%25');
    $result = (new ListQuery(searchable: ['name']))->apply(User::query(), $request)->get();

    expect($result)->toHaveCount(0);
});

it('memfilter nilai tunggal dan operator rentang', function (): void {
    $request = Request::create('/?filter[email]=andi@demo.test');
    $result = (new ListQuery(filterable: ['email']))->apply(User::query(), $request)->get();

    expect($result)->toHaveCount(1)->and($result->first()?->email)->toBe('andi@demo.test');

    $ids = User::query()->orderBy('id')->pluck('id');
    $request = Request::create('/?filter[id][gte]='.$ids[1]);
    $result = (new ListQuery(filterable: ['id']))->apply(User::query(), $request)->get();

    expect($result)->toHaveCount(2);
});

it('mengurutkan naik dan turun sesuai kolom yang diizinkan', function (): void {
    $ascending = (new ListQuery(sortable: ['name']))->apply(User::query(), Request::create('/?sort=name'))->get();
    $descending = (new ListQuery(sortable: ['name']))->apply(User::query(), Request::create('/?sort=-name'))->get();

    expect($ascending->first()?->name)->toBe('Andi Wijaya')
        ->and($descending->first()?->name)->toBe('Citra Lestari');
});

it('mengabaikan kolom sort yang tidak diizinkan', function (): void {
    $query = (new ListQuery(sortable: ['name'], defaultSort: '-id'))
        ->apply(User::query(), Request::create('/?sort=password'));

    expect($query->getQuery()->orders)->toBe([
        ['column' => 'id', 'direction' => 'desc'],
    ]);
});

it('membatasi per_page maksimum dan memakai nilai default saat tidak valid', function (): void {
    $listQuery = new ListQuery(maxPerPage: 100, defaultPerPage: 15);

    expect($listQuery->paginate(User::query(), Request::create('/?per_page=500'))->perPage())->toBe(100)
        ->and($listQuery->paginate(User::query(), Request::create('/?per_page=abc'))->perPage())->toBe(15);
});

it('membungkus paginasi dengan meta dan links', function (): void {
    Route::middleware('api')->get('/api/v1/_test/users', fn (): ApiCollection => new ApiCollection(User::query()->paginate(2)));

    $this->getJson('/api/v1/_test/users?page=2')
        ->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('data.0.name', 'Citra Lestari')
        ->assertJsonStructure(['data', 'meta', 'links' => ['first', 'prev', 'next', 'last']]);
});
