<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Local-only seeded staff logins (owner/manager/staff). Guarded inside
        // the seeder too, and only ever called here in the local environment.
        if (app()->environment('local')) {
            $this->call(DevAdminSeeder::class);
        }
    }
}
