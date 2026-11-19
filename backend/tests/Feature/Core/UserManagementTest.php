<?php

declare(strict_types=1);

use App\Domain\Core\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    seedRbac();
});

it('admin dapat membuat user baru beserta role-nya', function (): void {
    $admin = userWithRole('admin');

    $response = $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/users', [
            'name' => 'Siti Rahma',
            'email' => 'siti@example.test',
            'password' => 'rahasia123',
            'roles' => ['sales'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'siti@example.test')
        ->assertJsonPath('data.roles.0', 'sales');

    $created = User::query()->where('email', 'siti@example.test')->firstOrFail();

    expect(Hash::check('rahasia123', $created->password))->toBeTrue()
        ->and($created->hasRole('sales'))->toBeTrue();
});

it('menolak email duplikat dan password terlalu pendek', function (): void {
    $admin = userWithRole('admin');
    userWithRole('viewer', ['email' => 'dipakai@example.test']);

    $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/users', [
            'name' => 'Duplikat',
            'email' => 'dipakai@example.test',
            'password' => '123',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['errors' => ['email', 'password']]);
});

it('email user yang sudah dihapus dapat dipakai kembali', function (): void {
    $admin = userWithRole('admin');
    $removed = userWithRole('viewer', ['email' => 'lama@example.test']);
    $removed->delete();

    $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/users', [
            'name' => 'Pengguna Baru',
            'email' => 'lama@example.test',
            'password' => 'rahasia123',
            'roles' => ['viewer'],
        ])
        ->assertCreated();
});

it('admin dapat mengubah data dan role user', function (): void {
    $admin = userWithRole('admin');
    $target = userWithRole('viewer', ['email' => 'target@example.test']);

    $this->withHeaders(bearerFor($admin))
        ->putJson("/api/v1/users/{$target->getKey()}", [
            'name' => 'Nama Baru',
            'roles' => ['finance'],
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nama Baru')
        ->assertJsonPath('data.roles.0', 'finance');

    expect($target->fresh()?->hasRole('finance'))->toBeTrue();
});

it('menolak penghapusan akun sendiri', function (): void {
    $admin = userWithRole('admin');

    $this->withHeaders(bearerFor($admin))
        ->deleteJson("/api/v1/users/{$admin->getKey()}")
        ->assertStatus(403);
});

it('menolak pencabutan role admin terakhir', function (): void {
    $admin = userWithRole('admin');

    $this->withHeaders(bearerFor($admin))
        ->putJson("/api/v1/users/{$admin->getKey()}", ['roles' => ['viewer']])
        ->assertStatus(409)
        ->assertJsonPath('code', 'LAST_ADMIN');
});

it('soft delete menyembunyikan user dari daftar', function (): void {
    $admin = userWithRole('admin');
    $target = userWithRole('viewer', ['email' => 'hapus@example.test']);

    $this->withHeaders(bearerFor($admin))
        ->deleteJson("/api/v1/users/{$target->getKey()}")
        ->assertNoContent();

    expect(User::query()->find($target->getKey()))->toBeNull()
        ->and(User::withTrashed()->whereKey($target->getKey())->exists())->toBeTrue();
});
