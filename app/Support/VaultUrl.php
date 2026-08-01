<?php

namespace App\Support;

use App\Models\Dispensation;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Mint the short-lived, user-bound signed URL for a member photo or a POS signature (prompt 113) — the same
 * shape IssueDocumentUrl uses for documents: a ULID route param (never guessable), the configured TTL, and
 * `u` = the issuing user, so a leaked URL cannot be replayed by another session. The VIEW is access-logged at
 * the controller, not here. Returns null when there is no file, so callers can render nothing gracefully.
 */
class VaultUrl
{
    public static function photo(Member $member, User $actor): ?string
    {
        return filled($member->photo_path)
            ? self::signed('members.photo.show', ['member' => $member->id], $actor)
            : null;
    }

    public static function signature(Dispensation $dispensation, User $actor): ?string
    {
        return filled($dispensation->signature_path)
            ? self::signed('dispensations.signature.show', ['dispensation' => $dispensation->id], $actor)
            : null;
    }

    /** @param  array<string, mixed>  $params */
    private static function signed(string $route, array $params, User $actor): string
    {
        $ttl = (int) Settings::get('signed_url_ttl_seconds', 300);

        return URL::temporarySignedRoute($route, now()->addSeconds($ttl), array_merge($params, ['u' => $actor->id]));
    }
}
