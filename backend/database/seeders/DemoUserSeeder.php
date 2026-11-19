<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Enam akun demo, satu per role. Password diambil dari DEMO_PASSWORD.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) config('app.demo_password', 'password');

        $accounts = [
            ['admin@demo.test', 'Admin Demo', 'admin'],
            ['purchasing@demo.test', 'Purchasing Demo', 'purchasing'],
            ['sales@demo.test', 'Sales Demo', 'sales'],
            ['warehouse@demo.test', 'Warehouse Demo', 'warehouse'],
            ['finance@demo.test', 'Finance Demo', 'finance'],
            ['viewer@demo.test', 'Viewer Demo', 'viewer'],
        ];

        foreach ($accounts as [$email, $name, $role]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$role]);
        }
    }
}
