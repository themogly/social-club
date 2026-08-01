<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * First-run setup (prompt 78) — the ONLY production path to create the organisation and its first owner.
 * Without it there was no way to create the org after deploy, so the RAT rendered an empty data controller
 * (the `legal_name` it reads was never set) and no one could log in. Idempotent by refusal: it will not run
 * once an organisation exists unless --force. Roles/permissions are (re)seeded first (safe in every
 * environment). Non-interactive when every option is supplied (deploy automation); prompts otherwise.
 */
class Install extends Command
{
    protected $signature = 'csc:install
        {--name= : The association (club) name}
        {--legal-name= : The registered legal name — the RGPD data controller shown on the RAT}
        {--tax-id= : Tax id (CIF/NIF)}
        {--contact-email= : The association contact email}
        {--owner-name= : The first owner user full name}
        {--owner-email= : The first owner login email}
        {--owner-password= : The first owner password (min 8 chars)}
        {--force : Proceed even if an organisation already exists}';

    protected $description = 'First-run setup: create the organisation and its first owner so the club can go live';

    public function handle(): int
    {
        if (Organisation::query()->exists() && ! $this->option('force')) {
            $this->error('An organisation already exists — the club is already installed. Pass --force only to add another deliberately.');

            return self::FAILURE;
        }

        // Roles + permission catalogue: required in every environment and safe to re-run (idempotent seeder).
        $this->call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

        $data = [
            'name' => $this->option('name') ?? $this->ask('Association name'),
            'legal_name' => $this->option('legal-name') ?? $this->ask('Registered legal name (data controller on the RAT)'),
            'tax_id' => $this->option('tax-id') ?? $this->ask('Tax id (CIF/NIF)', ''),
            'contact_email' => $this->option('contact-email') ?? $this->ask('Association contact email', ''),
            'owner_name' => $this->option('owner-name') ?? $this->ask('First owner — full name'),
            'owner_email' => $this->option('owner-email') ?? $this->ask('First owner — login email'),
            'owner_password' => $this->option('owner-password') ?? $this->secret('First owner — password (min 8 chars)'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['required', 'string', 'max:255'], // the RAT data controller must not be blank
            'contact_email' => ['nullable', 'email', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'owner_password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $owner = DB::transaction(function () use ($data): User {
            $org = Organisation::create([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'],
                'tax_id' => $data['tax_id'] ?: null,
                'contact_email' => $data['contact_email'] ?: null,
            ]);

            // Seed the default expense categories so the club can record expenses (petty cash, overheads) from
            // day one — the same set the demo uses; idempotent firstOrCreate, keyed on the active locale (prompt 117).
            ExpenseCategorySeeder::seedFor($org->id, app()->getLocale());

            $owner = User::create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'], // 'hashed' cast on the model
                'active' => true,
            ]);
            $owner->assignRole(Role::OWNER->value);

            return $owner;
        });

        $this->info("Installed. Organisation '{$data['name']}' created with owner {$owner->email}.");
        $this->line('Next: log in to the admin panel, create your sede(s), then price every genetic at each sede.');

        return self::SUCCESS;
    }
}
