<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (env('SEED_ADMIN') != 1) {
            return;
        }

        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $name  = env('ADMIN_NAME', 'Admin');
        $pass  = env('ADMIN_PASSWORD', 'password');

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($pass)]
        );

        if (method_exists($user, 'assignRole')) {
            $user->assignRole('super_admin');
        }
    }
}
