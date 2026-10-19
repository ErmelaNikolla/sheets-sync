<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // updateOrCreate, so re-running setup.sh with a new ADMIN_PASSWORD changes the login.
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            ['name' => 'Admin', 'password' => env('ADMIN_PASSWORD', 'password')],
        );

        if (config('sheets.driver') === 'fake') {
            $this->call(DemoSeeder::class);
        }
    }
}
