<?php

namespace App\Http\Controllers;

use App\Models\MemberDocument;
use App\Support\VaultStream;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

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
        // The five protections now live in VaultStream (prompt 113), shared with the photo/signature endpoint.
        return VaultStream::respond(
            $request,
            (string) $document->path,
            fn () => Gate::authorize('view', $document),
            ['member_document_id' => $document->id],
        );
    }
}
