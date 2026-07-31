<?php

namespace App\Http\Controllers;

use App\Models\DocumentAccessLog;
use App\Models\MemberDocument;
use App\Support\DocumentVault;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Streams a member document from the PRIVATE disk. Reached only through a short-lived
 * signed URL from IssueDocumentUrl. Defence in depth (prompt 32, fixing audit S2):
 * (1) `signed` middleware enforces expiry; (2) the URL is bound to the issuing user id
 * (`u`) so a leaked/replayed URL is refused for a different session; (3) the view
 * policy enforces `member.documents.view` AND org ownership; (4) EVERY view — not just
 * issuance — writes a DocumentAccessLog row; (5) the ciphertext is decrypted only here.
 */
class MemberDocumentController extends Controller
{
    public function show(Request $request, MemberDocument $document): Response
    {
        // (2) Bind to the issuing user — a valid-but-leaked URL replayed by another session is refused.
        abort_unless((string) $request->query('u') === (string) $request->user()?->getKey(), 403);

        // (3) Permission + object-ownership (org) — the Gate pattern the receipt controllers use.
        Gate::authorize('view', $document);

        abort_unless(Storage::disk(DocumentVault::DISK)->exists($document->path), 404);

        // (4) Log the actual VIEW (prompt 04/17: "every view is access-logged" — the view, not just issuance).
        DocumentAccessLog::create([
            'actor_id' => $request->user()?->getKey(),
            'member_document_id' => $document->id,
            'viewed_at' => now(),
            'ip' => $request->ip(),
        ]);

        // (5) Decrypt at the streaming boundary only.
        return response(DocumentVault::get($document->path), 200, [
            'Content-Type' => DocumentVault::mimeFor($document->path),
            'Content-Disposition' => 'inline; filename="'.basename($document->path).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
