<?php

namespace App\Support\Reset;

use Illuminate\Support\Facades\File;

/**
 * Empty a local folder — ONLY one under storage/app, never storage/app itself — keeping the folder and a top-level
 * .gitignore. Shared by `csc:reset-for-launch` (304) and `csc:seed-staging` (327). Returns the files removed, or null
 * when the folder was refused for being outside storage/app (the caller says so).
 */
final class LocalStorageWiper
{
    public static function empty(string $dir): ?int
    {
        $base = realpath(storage_path('app'));
        $real = realpath($dir);
        if ($real === false) {
            return 0;
        }
        if ($base === false || ! str_starts_with($real.DIRECTORY_SEPARATOR, $base.DIRECTORY_SEPARATOR) || $real === $base) {
            return null;
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
}
