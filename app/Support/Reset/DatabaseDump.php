<?php

namespace App\Support\Reset;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Prompt 304 — the copy the pre-launch reset takes BEFORE it wipes anything: the whole database, gzipped, private (0600),
 * under storage/app/backups. MySQL through `mysqldump` with the configured connection (the password passed in the
 * environment, never on the command line); SQLite as plain SQL (schema + rows), which works for a file or an in-memory
 * database alike. Anything short of a non-empty file throws — and the reset aborts without touching the data.
 */
class DatabaseDump
{
    public function write(string $path): void
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}");

        $sql = match ($config['driver'] ?? null) {
            'mysql', 'mariadb' => $this->mysql($config),
            'sqlite' => $this->sqlite($connection),
            default => throw new RuntimeException('Unsupported database driver for the pre-launch dump: '.($config['driver'] ?? '?')),
        };

        File::ensureDirectoryExists(dirname($path), 0700);
        if (file_put_contents($path, (string) gzencode($sql, 9)) === false) {
            throw new RuntimeException("Could not write the dump to {$path}.");
        }
        chmod($path, 0600);
        clearstatcache(true, $path);
        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException("The dump at {$path} is missing or empty.");
        }
    }

    /** @param  array<string, mixed>  $config */
    private function mysql(array $config): string
    {
        $result = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])->timeout(600)->run([
            'mysqldump', '--single-transaction', '--no-tablespaces', '--routines',
            '--host='.($config['host'] ?? '127.0.0.1'), '--port='.($config['port'] ?? '3306'),
            '--user='.($config['username'] ?? ''), (string) ($config['database'] ?? ''),
        ]);
        if (! $result->successful() || trim($result->output()) === '') {
            throw new RuntimeException('mysqldump failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    private function sqlite(string $connection): string
    {
        $db = DB::connection($connection);
        $out = ["-- pre-launch reset dump\n"];
        foreach ($db->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $out[] = $table->sql.";\n";
            $quoted = str_replace('"', '""', (string) $table->name);
            foreach ($db->select("SELECT * FROM \"{$quoted}\"") as $row) {
                $values = array_map(fn ($v): string => $v === null ? 'NULL' : $db->getPdo()->quote((string) $v), (array) $row);
                $out[] = "INSERT INTO \"{$quoted}\" VALUES (".implode(', ', $values).");\n";
            }
        }

        return implode('', $out);
    }
}
