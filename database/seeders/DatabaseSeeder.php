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
        // Structural — every environment (roles + permission catalogue).
        $this->call(RolePermissionSeeder::class);

        // Local-only. Staff logins first (owner/manager/staff), then the demo
        // organisation, premises, catalogue, members and a fortnight of activity.
        if (app()->environment('local')) {
            $this->call([
                DevAdminSeeder::class,
                DemoDataSeeder::class,
            ]);
        }
    }
}
