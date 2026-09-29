<?php

namespace App\Console\Commands;

use App\Actions\RecordAuditLog;
use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use App\Support\ActiveScope;
use App\Support\Reset\DatabaseDump;
use App\Support\Reset\RedisPurger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Prompt 304 — wipe the TEST club and install the real one, safely, once. Interactive by design (passwords are asked, never
 * passed). It refuses, before touching anything, when the club has been launched (`csc:launch` — no flag overrides it),
 * when a lockdown is active, and unless the operator types the association's exact name AND answers yes.
 *
 * Then, stopping at the first failure: maintenance mode; a gzipped 0600 dump of the whole database FIRST (no dump, no wipe
 * — the site comes back up); `migrate:fresh`; this app's Redis keys only (by prefix, never FLUSHALL); the local public,
 * member-import and (local) documents files, keeping the directories and their .gitignore; remote documents only with
 * `--purge-documents` and their own confirmation; `csc:install` + `csc:sync-permissions` unless `--no-install`; one
 * `system.reset_for_launch` audit entry in the new database (file and object counts, no personal data); back up.
 *
 * It never touches `.env` or `APP_KEY`, anything outside storage/app, or the bucket itself.
 */
class ResetForLaunch extends Command
{
    protected $signature = 'csc:reset-for-launch
        {--purge-documents : Also delete every object in the remote (S3/R2) documents store, after its own confirmation}
        {--no-install : Stop after the wipe, without installing the new club}';

    protected $description = 'Before launch only: dump, then wipe the test club (database, files, this app\'s Redis keys) and install the real one';

    public function handle(): int
    {
        if (Organisation::launched() !== null) {
            $this->error(__('Este club ya está en marcha. El reinicio está bloqueado para siempre.'));

            return self::FAILURE;
        }

        $org = Organisation::query()->first();
        if ($org === null) {
            $this->error(__('No hay ningún club instalado. Usa `php artisan csc:install`.'));

            return self::FAILURE;
        }
        if (OrganisationLockdown::active($org->id) !== null) {
            $this->error(__('Hay un bloqueo de emergencia activo. Levántalo antes de reiniciar.'));

            return self::FAILURE;
        }
        if ($this->ask(__('Escribe el nombre de la asociación para confirmar')) !== $org->name) {
            $this->error(__('El nombre no coincide. No se ha tocado nada.'));

            return self::FAILURE;
        }
        if (! $this->confirm(__('Se borrarán TODOS los datos de :name. ¿Continuar?', ['name' => $org->name]))) {
            $this->warn(__('Cancelado. No se ha tocado nada.'));

            return self::FAILURE;
        }

        // Pre-flight: a step that cannot run must fail BEFORE the wipe, not after it (found in the sandbox: Redis
        // unreachable after `migrate:fresh` had already run).
        try {
            app(RedisPurger::class)->check();
        } catch (Throwable $e) {
            $this->error(__('No se puede conectar con Redis; no se ha tocado nada.').' '.$e->getMessage());

            return self::FAILURE;
        }

        $this->call('down');

        // 1. The dump — ALWAYS first. No dump, no wipe.
        $dump = storage_path('app/backups/pre-launch-reset-'.now()->format('Y-m-d-His').'.sql.gz');
        try {
            app(DatabaseDump::class)->write($dump);
        } catch (Throwable $e) {
            $this->error(__('No se pudo hacer la copia de la base de datos; no se ha borrado nada.').' '.$e->getMessage());
            $this->call('up');

            return self::FAILURE;
        }
        $this->info(__('Copia guardada en :path', ['path' => $dump]));
        $this->warn(__('Este archivo contiene TODOS los datos de prueba sin cifrar. Bórralo cuando estés seguro.'));

        try {
            // 2. The database.
            $this->call('migrate:fresh', ['--force' => true]);

            // 3. This app's Redis keys only.
            $redisKeys = app(RedisPurger::class)->purge();
            $this->info(__('Claves de Redis de esta aplicación borradas: :count', ['count' => $redisKeys]));

            // 4. Local files, keeping the directories and their .gitignore.
            $directories = [(string) config('filesystems.disks.public.root'), storage_path('app/member-imports')];
            if (config('filesystems.disks.documents.driver') === 'local') {
                $directories[] = (string) config('filesystems.disks.documents.root');
            }
            $files = array_sum(array_map(fn (string $dir): int => $this->emptyDirectory($dir), $directories));
            $this->info(__('Archivos locales borrados: :count', ['count' => $files]));

            // 5. Remote documents.
            $objects = config('filesystems.disks.documents.driver') === 's3' ? $this->remoteDocuments() : 0;

            // 6. The real club.
            if (! $this->option('no-install')) {
                if ($this->call('csc:install') !== self::SUCCESS) {
                    throw new RuntimeException(__('La instalación del nuevo club no terminó.'));
                }
                $this->call('csc:sync-permissions');
            }

            // 7. The record, in the NEW database — counts and the dump's name, no personal data.
            $new = Organisation::query()->first();
            app(ActiveScope::class)->setOrganisation($new?->id);
            (new RecordAuditLog)->handle('system.reset_for_launch', $new, null, [
                'dump' => basename($dump), 'files_removed' => $files, 'objects_removed' => $objects, 'redis_keys_removed' => $redisKeys,
            ]);
        } catch (Throwable $e) {
            $this->error(__('El reinicio se detuvo: :error. La copia está en :path.', ['error' => $e->getMessage(), 'path' => $dump]));
            $this->call('up');

            return self::FAILURE;
        }

        $this->call('up');
        $this->newLine();
        $this->info(__('Siguientes pasos:'));
        foreach ([
            'php artisan config:cache',
            'php artisan horizon:terminate',
            __('Vuelve a registrar cada tablet (los registros anteriores se han borrado).'),
            __('Configura las sedes, el almacén, el personal con sus PIN y el catálogo; después importa los socios.'),
            __('Ejecuta `php artisan csc:launch` el día en que se atienda al primer socio real.'),
            __('Borra la copia (:file) cuando estés seguro.', ['file' => basename($dump)]),
        ] as $i => $step) {
            $this->line(($i + 1).'. '.$step);
        }

        return self::SUCCESS;
    }

    /** Delete everything inside `$dir` except a top-level .gitignore; keep the directory. Only ever under storage/app. */
    private function emptyDirectory(string $dir): int
    {
        $base = realpath(storage_path('app'));
        $real = realpath($dir);
        if ($real === false) {
            return 0;
        }
        if ($base === false || ! str_starts_with($real.DIRECTORY_SEPARATOR, $base.DIRECTORY_SEPARATOR) || $real === $base) {
            $this->warn(__('No se toca :dir: está fuera de storage/app.', ['dir' => $dir]));

            return 0;
        }

        $removed = 0;
        foreach (File::allFiles($real, true) as $file) {
            if ($file->getFilename() === '.gitignore' && $file->getPath() === $real) {
                continue;
            }
            File::delete($file->getPathname());
            $removed++;
        }
        foreach (File::directories($real) as $sub) {
            File::deleteDirectory($sub);
        }

        return $removed;
    }

    /** The remote documents store: deleted only with --purge-documents and its own confirmation; never the bucket. */
    private function remoteDocuments(): int
    {
        $disk = Storage::disk('documents');
        $objects = $disk->allFiles();
        $count = count($objects);

        if (! $this->option('purge-documents')) {
            $this->warn(__('Quedan :count objetos en el almacén de documentos. Para vaciarlo a mano: Cloudflare → R2 → el bucket de documentos → selecciona todo → Borrar (no borres el bucket).', ['count' => $count]));

            return 0;
        }
        if ($count === 0) {
            return 0;
        }
        if (! $this->confirm(__('Se borrarán :count objetos del almacén de documentos. ¿Continuar?', ['count' => $count]))) {
            $this->warn(__('Documentos remotos conservados.'));

            return 0;
        }
        foreach (array_chunk($objects, 500) as $chunk) {
            $disk->delete($chunk);
        }

        return $count;
    }
}
