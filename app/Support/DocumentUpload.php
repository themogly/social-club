<?php

namespace App\Support;

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
}
