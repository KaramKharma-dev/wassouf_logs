<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'wassouf@store.com');
        $pass  = env('ADMIN_PASSWORD', 'Aacchh123');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'      => 'Admin',
                'password'  => Hash::make($pass),
                'is_admin'  => true,
            ]
        );
    }
}
