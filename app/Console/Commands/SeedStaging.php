<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Support\Reset\LocalStorageWiper;
use App\Support\Reset\RedisPurger;
use App\Support\StagingSeed;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DevAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prompt 327 — fill a STAGING site (formacion.padron.app) with a believable, entirely fictional club, so every change
 * can be tried before it reaches the live one. Never a copy of live: members' personal and special-category data stay
 * where they are, and staging has its own APP_KEY.
 *
 * It refuses, in this order, naming the refusal: an environment other than `staging` (or `local`, for the sandbox) —
 * never `production`; a club that has launched (304's latch); `--fresh` without the operator typing «staging»; and data
 * already there without `--fresh`. With `--fresh` it wipes like 304's reset (migrate:fresh, this app's Redis keys by
 * prefix — never FLUSHALL — and the local public and member-imports folders), then seeds roles, the three dev accounts
 * and the demo club, and syncs the permissions. The demo seeders run on staging ONLY here ({@see StagingSeed}).
 */
class SeedStaging extends Command
{
    protected $signature = 'csc:seed-staging {--fresh : Wipe the database, this app\'s Redis keys and the local uploads first}';

    protected $description = 'Staging only: fill the site with the fictional demo club (never a copy of live)';

    public function handle(): int
    {
        $env = (string) app()->environment();
        if (! in_array($env, ['staging', 'local'], true)) {
            $this->error(__('Solo en staging (o en local). Este entorno es «:env»: no se ha tocado nada.', ['env' => $env]));

            return self::FAILURE;
        }
        if (Organisation::launched() !== null) {
            $this->error(__('Un club ya está en marcha en esta base de datos: no se siembran datos de prueba.'));

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            if ($this->ask(__('Escribe «staging» para borrar todo y empezar de cero')) !== 'staging') {
                $this->error(__('No has escrito «staging». No se ha tocado nada.'));

                return self::FAILURE;
            }
            $this->wipe();
        } elseif (Organisation::query()->exists()) {
            $this->error(__('Ya hay datos. Usa --fresh para empezar de cero.'));

            return self::FAILURE;
        }

        app()->instance(StagingSeed::FLAG, true);
        try {
            foreach ([RolePermissionSeeder::class, DevAdminSeeder::class, DemoDataSeeder::class] as $seeder) {
                $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
            }
        } finally {
            app()->forgetInstance(StagingSeed::FLAG);
        }
        $this->call('csc:sync-permissions');

        $this->newLine();
        $this->info(__('Club de prueba listo. Cuentas (contraseña «password»):'));
        foreach ([['owner@club.test', '1234'], ['manager@club.test', '2345'], ['staff@club.test', '3456']] as [$email, $pin]) {
            $this->line("  {$email}  ·  PIN {$pin}");
        }
        $this->newLine();
        $this->info(__('Siguientes pasos:'));
        foreach ([
            'php artisan config:cache',
            'php artisan queue:restart',
            __('Entra como owner@club.test y comprueba la franja «ENTORNO DE PRUEBAS».'),
            __('Vuelve a ejecutar este comando con --fresh cuando quieras un club de prueba limpio.'),
        ] as $i => $step) {
            $this->line(($i + 1).'. '.$step);
        }

        return self::SUCCESS;
    }

    /** 304's wipe, without its dump (there is nothing real to keep on staging). */
    private function wipe(): void
    {
        $this->call('migrate:fresh', ['--force' => true]);

        try {
            $purger = app(RedisPurger::class);
            $purger->check();
            $this->info(__('Claves de Redis de esta aplicación borradas: :count', ['count' => $purger->purge()]));
        } catch (Throwable $e) {
            $this->warn(__('Redis no está disponible: no se han borrado claves. :error', ['error' => $e->getMessage()]));
        }

        $files = 0;
        foreach ([(string) config('filesystems.disks.public.root'), storage_path('app/member-imports')] as $dir) {
            $removed = LocalStorageWiper::empty($dir);
            if ($removed === null) {
                $this->warn(__('No se toca :dir: está fuera de storage/app.', ['dir' => $dir]));
            }
            $files += (int) $removed;
        }
        $this->info(__('Archivos locales borrados: :count', ['count' => $files]));
    }
}
