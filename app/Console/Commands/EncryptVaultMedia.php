<?php

namespace App\Console\Commands;

use App\Support\DocumentVault;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * One-off (prompt 113): encrypt any member photos and POS signatures already sitting PLAINTEXT on the
 * documents disk, so existing installs match the new write path. Idempotent — a file that already decrypts
 * is left untouched, so running it twice never double-encrypts or corrupts. Manual, like csc:install.
 */
class EncryptVaultMedia extends Command
{
    protected $signature = 'csc:encrypt-vault-media {--dry-run : Report what would be encrypted without writing}';

    protected $description = 'Encrypt plaintext member photos and POS signatures already on the documents disk';

    /** @var list<string> */
    private const DIRECTORIES = ['member-photos', 'signatures'];

    public function handle(): int
    {
        $disk = Storage::disk(DocumentVault::DISK);
        $dryRun = (bool) $this->option('dry-run');
        $encrypted = 0;
        $alreadyEncrypted = 0;

        foreach (self::DIRECTORIES as $directory) {
            foreach ($disk->files($directory) as $path) {
                $contents = (string) $disk->get($path);
                if ($contents === '') {
                    continue;
                }

                try {
                    Crypt::decryptString($contents);
                    $alreadyEncrypted++; // valid ciphertext already — leave it
                } catch (DecryptException) {
                    if (! $dryRun) {
                        $disk->put($path, Crypt::encryptString($contents));
                    }
                    $encrypted++;
                }
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Encrypted {$encrypted} plaintext file(s); {$alreadyEncrypted} already encrypted.");

        return self::SUCCESS;
    }
}
