<?php

declare(strict_types=1);

use App\Domain\Core\Models\ApiRequestLog;
use App\Domain\Core\Models\AuditLog;
use App\Domain\Core\Models\User;

beforeEach(function (): void {
    seedRbac();
});

it('mencatat audit created tanpa atribut sensitif', function (): void {
    $user = userWithRole('viewer', ['email' => 'audit@example.test']);

    $log = AuditLog::query()
        ->where('auditable_type', 'user')
        ->where('auditable_id', $user->getKey())
        ->where('event', 'created')
        ->firstOrFail();

    expect($log->new_values)->toHaveKey('email')
        ->and($log->new_values['email'])->toBe('audit@example.test')
        ->and($log->new_values)->not->toHaveKey('password')
        ->and($log->old_values)->toBeNull()
        ->and($log->diff)->toHaveKey('email');
});

it('mencatat audit updated dengan old, new, dan diff', function (): void {
    $user = userWithRole('viewer', ['name' => 'Nama Awal', 'email' => 'ubah@example.test']);

    $user->update(['name' => 'Nama Akhir']);

    $log = AuditLog::query()
        ->where('auditable_id', $user->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($log->old_values)->toBe(['name' => 'Nama Awal'])
        ->and($log->new_values)->toBe(['name' => 'Nama Akhir'])
        // jsonb menyimpan key terurut, jadi perbandingan tidak boleh bergantung urutan.
        ->and($log->diff)->toEqual(['name' => ['old' => 'Nama Awal', 'new' => 'Nama Akhir']]);
});

it('tidak mencatat audit bila tidak ada atribut yang berubah', function (): void {
    $user = userWithRole('viewer', ['name' => 'Tetap']);

    $before = AuditLog::query()->count();
    $user->update(['name' => 'Tetap']);

    expect(AuditLog::query()->count())->toBe($before);
});

it('mencatat user pelaku pada audit saat lewat API', function (): void {
    $admin = userWithRole('admin');

    $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/users', [
            'name' => 'Dicatat',
            'email' => 'dicatat@example.test',
            'password' => 'rahasia123',
            'roles' => ['viewer'],
        ])
        ->assertCreated();

    $created = User::query()->where('email', 'dicatat@example.test')->firstOrFail();

    $log = AuditLog::query()
        ->where('auditable_id', $created->getKey())
        ->where('event', 'created')
        ->firstOrFail();

    expect($log->user_id)->toBe($admin->getKey())
        ->and($log->ip)->not->toBeNull();
});

it('menyajikan riwayat audit per resource', function (): void {
    $admin = userWithRole('admin');
    $target = userWithRole('viewer', ['email' => 'riwayat@example.test']);
    $target->update(['name' => 'Diubah']);

    $this->withHeaders(bearerFor($admin))
        ->getJson("/api/v1/users/{$target->getKey()}/audit")
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.event', 'updated')
        ->assertJsonPath('data.1.event', 'created')
        ->assertJsonStructure(['data' => [['id', 'event', 'auditable_type', 'diff', 'user']]]);
});

it('memfilter audit log berdasarkan model, event, dan tanggal', function (): void {
    $admin = userWithRole('admin');
    $target = userWithRole('viewer', ['email' => 'filter@example.test']);
    $target->update(['name' => 'Setelah Filter']);

    $headers = bearerFor($admin);

    $this->withHeaders($headers)
        ->getJson('/api/v1/audit-logs?filter[auditable_type]=user&filter[event]=updated')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event', 'updated');

    $this->withHeaders($headers)
        ->getJson('/api/v1/audit-logs?filter[created_at][from]='.now()->addDay()->toDateString())
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->withHeaders($headers)
        ->getJson('/api/v1/audit-logs?filter[auditable_id]='.$target->getKey())
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('mencatat request mutasi ke api_request_logs', function (): void {
    $admin = userWithRole('admin');

    $this->withHeaders(bearerFor($admin))
        ->postJson('/api/v1/users', [
            'name' => 'Log Request',
            'email' => 'log@example.test',
            'password' => 'rahasia123',
        ])
        ->assertCreated();

    $log = ApiRequestLog::query()->where('path', 'api/v1/users')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log?->method)->toBe('POST')
        ->and($log?->status)->toBe(201)
        ->and($log?->user_id)->toBe($admin->getKey());
});

it('tidak mencatat request GET ke api_request_logs', function (): void {
    $admin = userWithRole('admin');

    $this->withHeaders(bearerFor($admin))->getJson('/api/v1/auth/me')->assertOk();

    expect(ApiRequestLog::query()->count())->toBe(0);
});
