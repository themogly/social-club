<?php

namespace App\Actions\Members;

use App\Models\MemberDocument;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\URL;

/**
 * Issue a short-lived signed URL to a member document (ID scan, consent, etc.).
 * Only `member.documents.view` may proceed; the URL expires after the configured TTL,
 * the path (a ULID) is never guessable, and it is BOUND to the issuing user. The actual
 * VIEW is access-logged in MemberDocumentController — issuance-only logging (audit S2)
 * missed reloaded/prefetched/leaked/replayed opens, so "every view is logged" now means
 * the view itself, not just minting the link.
 */
class IssueDocumentUrl
{
    /**
     * `$operator` (prompt 262): from the COUNTER, the PIN operator — they must hold `member.documents.view` (255's
     * rule: the operator decides, not the tablet), and they are signed into the URL (`op`) so the access log and the
     * view's own authorisation name them. The URL stays bound to the session that fetches it (`u`).
     */
    public function handle(MemberDocument $document, User $actor, ?User $operator = null): string
    {
        abort_unless(($operator ?? $actor)->can('member.documents.view'), 403);

        $ttl = (int) Settings::get('signed_url_ttl_seconds', 300);

        // Bound to the issuing user (audit S2) — the actual VIEW is access-logged in the controller,
        // not here, so a reloaded/prefetched/leaked/replayed URL no longer views without a log entry.
        return URL::temporarySignedRoute('members.documents.show', now()->addSeconds($ttl), array_filter([
            'document' => $document->id,
            'u' => $actor->id,
            'op' => $operator?->id,
        ]));
    }
}
