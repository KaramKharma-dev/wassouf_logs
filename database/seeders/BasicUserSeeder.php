<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class BasicUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => 'secret'] // سيُهَاش تلقائياً
        );
    }
}
