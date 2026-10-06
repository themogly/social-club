<?php

namespace App\Support;

use App\Models\MemberApplication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Prompt 361 — "it can knock off the photos if a field is blank and it's submitted". The public application form is a
 * plain multipart POST; a refusal redirects back with `old()` input, and a browser can never refill a file input. So the
 * photo and the ID scan that ARRIVED with a refused submit, and are themselves valid, are kept here until the next try:
 *
 *  - encrypted through the vault (Article 9 data), under `kept-uploads/{application}/{session}/`;
 *  - bound to THIS application's token AND this browser session (the map lives in the session; a different browser on
 *    the same link gets an empty form);
 *  - used when the next submit sends no new file, adopted into the directory a fresh upload would land in;
 *  - deleted on submit, on «Salir sin enviar» / a handover ended by a staff PIN, when the invitation expires, and by the
 *    24-hour purge (`applications:purge-kept-uploads`) as a backstop — an unsubmitted ID scan never lingers.
 */
final class KeptUploads
{
    public const FIELDS = ['photo', 'document_scan'];

    public const DIRECTORY = 'kept-uploads';

    private const SESSION = 'application_kept';

    /** @return array<string, string> field => vault path, for this token in THIS session (files that still exist) */
    public static function for(string $token): array
    {
        $map = (array) session(self::SESSION.'.'.self::tokenKey($token), []);

        return array_filter($map, fn ($path): bool => is_string($path) && Storage::disk(DocumentVault::DISK)->exists($path));
    }

    /**
     * Keep the valid files of a refused submit. A file that is itself the reason for the refusal (too big, wrong type) is
     * not kept — the applicant must choose another.
     *
     * @param  array<string, mixed>  $files
     */
    public static function keep(string $token, MemberApplication $application, array $files): void
    {
        $rules = ApplicationShape::files();
        foreach (self::FIELDS as $field) {
            $file = $files[$field] ?? null;
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $fieldRules = array_values(array_filter($rules[$field], fn ($rule): bool => $rule !== 'required'));
            if (Validator::make([$field => $file], [$field => $fieldRules])->fails()) {
                continue;
            }

            self::drop($token, $field);
            $path = DocumentVault::storeUpload($file, self::DIRECTORY.'/'.$application->id.'/'.self::sessionDir());
            session([self::SESSION.'.'.self::tokenKey($token).'.'.$field => $path]);
        }
    }

    /** Move a kept file into `$directory`, as a fresh upload would land, and delete the kept copy. */
    public static function adopt(string $path, string $directory): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';
        $target = trim($directory, '/').'/'.Str::ulid().'.'.$extension;
        DocumentVault::put($target, DocumentVault::get($path));
        Storage::disk(DocumentVault::DISK)->delete($path);

        return $target;
    }

    /** Forget (and delete) everything kept for this token in this session. */
    public static function forget(string $token): void
    {
        foreach (self::FIELDS as $field) {
            self::drop($token, $field);
        }
        session()->forget(self::SESSION.'.'.self::tokenKey($token));
    }

    /** Everything this browser session kept, for any token — a handover ended, the session closing. */
    public static function forgetSession(): void
    {
        foreach ((array) session(self::SESSION, []) as $map) {
            foreach ((array) $map as $path) {
                if (is_string($path)) {
                    Storage::disk(DocumentVault::DISK)->delete($path);
                }
            }
        }
        session()->forget(self::SESSION);
    }

    /** Every kept file of an application, whatever the session — its invitation expired or it was submitted. */
    public static function discardApplication(string $applicationId): void
    {
        Storage::disk(DocumentVault::DISK)->deleteDirectory(self::DIRECTORY.'/'.$applicationId);
    }

    private static function drop(string $token, string $field): void
    {
        $path = session(self::SESSION.'.'.self::tokenKey($token).'.'.$field);
        if (is_string($path)) {
            Storage::disk(DocumentVault::DISK)->delete($path);
        }
        session()->forget(self::SESSION.'.'.self::tokenKey($token).'.'.$field);
    }

    /** The token never appears in the session or a path in the clear. */
    private static function tokenKey(string $token): string
    {
        return substr(hash('sha256', $token), 0, 32);
    }

    private static function sessionDir(): string
    {
        return substr(hash('sha256', session()->getId()), 0, 32);
    }
}
