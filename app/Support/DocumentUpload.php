<?php

namespace App\Support;

use Closure;
use Filament\Forms\Components\BaseFileUpload;
use Throwable;

/**
 * The ONE definition of how large a document upload may be (prompt 164).
 *
 * Before this, three limits applied to the same upload and none of them agreed: nginx's
 * `client_max_body_size` (the smallest, and the one that actually fired), PHP's
 * `upload_max_filesize`/`post_max_size`, and Livewire's 12 MB — while the application
 * itself declared nothing at all. So a perfectly ordinary 3.86 MB phone photo of a DNI
 * was refused by the web server before PHP ran: nothing in the Laravel log, and the
 * member saw only Livewire's generic "failed to upload".
 *
 * Every `FileUpload` on the private `documents` disk and the applicant photo rule read
 * their ceiling from here. `UploadLimitsTest` enumerates them, so an upload field added
 * later without one fails the suite.
 */
class DocumentUpload
{
    /**
     * Used when the config is missing or a stale cache returns nothing. A settings/config
     * read must degrade gracefully, never throw — least of all over an upload limit.
     */
    private const FALLBACK_KB = 12288;

    /** The ceiling, in kilobytes — what Filament's `maxSize()` and Laravel's `max:` both want. */
    public static function maxKilobytes(): int
    {
        $kb = (int) config('documents.max_upload_kb', self::FALLBACK_KB);

        return $kb > 0 ? $kb : self::FALLBACK_KB;
    }

    /** The validation rule fragment, e.g. `max:12288`. */
    public static function maxRule(): string
    {
        return 'max:'.self::maxKilobytes();
    }

    /**
     * Human label for the ceiling, e.g. "12 MB". Rounded DOWN, deliberately: a stated
     * limit that is larger than the real one sends someone off to pick a file that will
     * then be refused, which is the failure this whole branch is about.
     */
    public static function limitLabel(): string
    {
        return intdiv(self::maxKilobytes(), 1024).' MB';
    }

    /**
     * Helper text for a document upload field: the field's own explanation (if any) followed
     * by the limit, so a member on a phone knows the ceiling BEFORE they choose a file and
     * wait for an upload that was never going to succeed.
     */
    public static function helperText(?string $context = null): string
    {
        $limit = __('Tamaño máximo :size.', ['size' => self::limitLabel()]);

        return filled($context) ? $context.' '.$limit : $limit;
    }

    /**
     * Stop a `documents`-disk FileUpload from ever addressing the disk for a URL (security audit, Phase C
     * carry-forward).
     *
     * Filament's `BaseFileUpload::getUploadedFile()` calls `$storage->temporaryUrl()` for ANY field with
     * `visibility('private')`. `previewable(false)` does not prevent it — that only sets a flag the FilePond
     * JS reads. With `DOCUMENTS_DRIVER=s3` this returned a live presigned, bucket-direct URL to the object:
     * `https://<bucket>.s3.<region>.amazonaws.com/member-id-scans/<key>?X-Amz-Algorithm=...`, valid for 30
     * minutes rounded up to the hour, which bypasses MemberDocumentController and VaultStream entirely — so
     * no policy check, no `u` user-binding, and NO `DocumentAccessLog` row. "Every view of an Article 9 file
     * is access-logged" was false in production for the panel's own form fields.
     *
     * (On the local driver the same path throws, is caught, and falls through to `url()` → `/storage/<path>`,
     * a dead link into the public symlink where the file is not. Harmless, and the reason nobody noticed.)
     *
     * So: the field keeps its name and size, and hands out no URL at all. The Article-9 member fields already
     * offer the correct viewer through their `hintAction`, which goes via the signed, authorised, logged
     * endpoint. `DocumentsDiskUrlTest` enumerates every field on the disk, so one added later without this
     * fails the suite.
     */
    public static function withoutDirectUrl(): Closure
    {
        return static function (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array {
            try {
                $size = $component->getDisk()->size($file);
            } catch (Throwable) {
                return null;   // the file is gone — same outcome as Filament's own metadata failure
            }

            return [
                'name' => (is_array($storedFileNames) ? ($storedFileNames[$file] ?? null) : $storedFileNames) ?? basename($file),
                'size' => $size,
                'type' => null,   // the bytes on disk are ciphertext; a sniffed type would be a lie
                'url' => null,    // never a direct disk URL — see the note above
            ];
        };
    }
}
