<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only a sign-in for local development — the scaffold has no domain of its
     * own. Sub-projects that add tables should add their own seeders and call
     * them from here.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'dev@laravel-vilt.test'],
            ['name' => 'Ethan Basham', 'password' => 'password', 'email_verified_at' => now()],
        );
    }
}
