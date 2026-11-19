<?php

declare(strict_types=1);

use App\Domain\Core\Models\ApiRequestLog;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    seedRbac();
});

it('hanya menyimpan kolom yang diizinkan pada api_request_logs', function (): void {
    expect(Schema::getColumnListing('api_request_logs'))->toEqualCanonicalizing([
        'id',
        'user_id',
        'method',
        'path',
        'status',
        'duration_ms',
        'ip',
        'user_agent',
        'created_at',
    ]);
});

it('tidak menyimpan body, header Authorization, maupun query string', function (): void {
    $admin = userWithRole('admin');

    $this->withHeaders([
        ...bearerFor($admin),
        'X-Secret-Header' => 'jangan-disimpan',
    ])->postJson('/api/v1/users?token=rahasia-query&debug=1', [
        'name' => 'Log Bersih',
        'email' => 'bersih@example.test',
        'password' => 'password-sangat-rahasia',
        'roles' => ['viewer'],
    ])->assertCreated();

    $log = ApiRequestLog::query()->latest('id')->firstOrFail();
    $row = $log->getAttributes();
    $serialized = json_encode($row, JSON_THROW_ON_ERROR);

    expect($log->path)->toBe('api/v1/users')
        ->and($log->path)->not->toContain('?')
        ->and($serialized)->not->toContain('rahasia-query')
        ->and($serialized)->not->toContain('debug=1')
        ->and($serialized)->not->toContain('password-sangat-rahasia')
        ->and($serialized)->not->toContain('Bearer')
        ->and($serialized)->not->toContain('jangan-disimpan')
        ->and($row)->not->toHaveKey('body')
        ->and($row)->not->toHaveKey('headers')
        ->and($row)->not->toHaveKey('query');
});

it('mencatat login yang gagal tanpa menyimpan password', function (): void {
    $this->postJson('/api/v1/auth/login?email=x', [
        'email' => 'gagal@example.test',
        'password' => 'password-yang-tidak-boleh-tersimpan',
    ])->assertStatus(401);

    $log = ApiRequestLog::query()->latest('id')->firstOrFail();

    expect($log->path)->toBe('api/v1/auth/login')
        ->and($log->status)->toBe(401)
        ->and(json_encode($log->getAttributes(), JSON_THROW_ON_ERROR))
        ->not->toContain('password-yang-tidak-boleh-tersimpan');
});
