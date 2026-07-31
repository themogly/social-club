<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Read/write helper for the private `documents` disk (prompt 32, fixing audit S1).
 * Every byte written here for the ARTICLE-9 member documents — ID scans, medical
 * certificates and the generated PDFs (which embed the ID number) — is ENCRYPTED at
 * rest with the app key (`Crypt::encryptString`, AES-256), and decrypted ONLY at the
 * point of streaming through the access-logged, signed-URL, authorised endpoint
 * (`MemberDocumentController`). The private disk + signed URL protect the HTTP path;
 * this protects the disk / backup / snapshot / object-store path — the two are NOT
 * redundant, both matter. See DECISIONS.md for the (documented) scope: the member
 * photo, POS signature and non-member uploads share this disk but read inline through
 * other paths and are a tracked follow-up.
 */
class DocumentVault
{
    public const DISK = 'documents';

    /** Encrypt $contents and write them to $path on the private disk. */
    public static function put(string $path, string $contents): void
    {
        self::disk()->put($path, Crypt::encryptString($contents));
    }

    /** Read and decrypt the file at $path. Throws on a missing or tampered payload. */
    public static function get(string $path): string
    {
        return Crypt::decryptString((string) self::disk()->get($path));
    }

    /** Store an uploaded file ENCRYPTED under $directory, returning its stored path. */
    public static function storeUpload(UploadedFile $file, string $directory): string
    {
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
        $path = trim($directory, '/').'/'.Str::ulid().'.'.$extension;

        self::put($path, (string) $file->get());

        return $path;
    }

    /** Best-effort content type from the stored path's extension (the bytes on disk are ciphertext). */
    public static function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }

    private static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
