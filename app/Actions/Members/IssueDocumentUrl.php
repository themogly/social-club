<?php

namespace App\Actions\Members;

use App\Models\DocumentAccessLog;
use App\Models\MemberDocument;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\URL;

/**
 * Issue a short-lived signed URL to a member document (ID scan, consent, etc.).
 * EVERY attempt — allowed or denied — writes a DocumentAccessLog entry ("who
 * opened whose passport scan"). Only `member.documents.view` may proceed; the URL
 * expires after the configured TTL and the path (a ULID) is never guessable.
 */
class IssueDocumentUrl
{
    public function handle(MemberDocument $document, User $actor): string
    {
        DocumentAccessLog::create([
            'actor_id' => $actor->id,
            'member_document_id' => $document->id,
            'viewed_at' => now(),
            'ip' => Request::ip(),
        ]);

        abort_unless($actor->can('member.documents.view'), 403);

        $ttl = (int) Settings::get('signed_url_ttl_seconds', 300);

        return URL::temporarySignedRoute('members.documents.show', now()->addSeconds($ttl), ['document' => $document->id]);
    }
}
