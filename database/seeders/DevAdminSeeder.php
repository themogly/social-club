<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local-only seeded staff accounts so a fresh DB (and every migrate:fresh) has
 * working panel logins without hand-creating users. Pinned credentials — do not
 * invent per-run (documented in SETUP.md). NEVER runs outside `local`.
 *
 * Roles and POS PINs (owner 1234 / manager 2345 / staff 3456) are attached in
 * prompt 02 once those columns exist; this base seeder only needs the three
 * accounts to be email-verified so they pass the panel gate.
 */
class DevAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DevAdminSeeder skipped — not the local environment.');

            return;
        }

        $accounts = [
            ['name' => 'Club Owner', 'email' => 'owner@club.test', 'pin' => '1234'],
            ['name' => 'Club Manager', 'email' => 'manager@club.test', 'pin' => '2345'],
            ['name' => 'Club Staff', 'email' => 'staff@club.test', 'pin' => '3456'],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make('password'),
                    'pin' => Hash::make($account['pin']),   // hashed; roles assigned in prompt 02
                    'active' => true,
                    'email_verified_at' => now(),
                ],
            );
        }

        $this->command?->info('Seeded dev staff: owner@club.test / manager@club.test / staff@club.test (password: "password").');
    }
}
