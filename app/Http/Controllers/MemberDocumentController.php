<?php

namespace App\Http\Controllers;

use App\Models\MemberDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a member document from the PRIVATE disk. Reached only through a
 * short-lived signed URL from IssueDocumentUrl (the `signed` middleware enforces
 * expiry); the file is never at a public/guessable path.
 */
class MemberDocumentController extends Controller
{
    public function show(MemberDocument $document): StreamedResponse
    {
        $disk = Storage::disk('documents');

        abort_unless($disk->exists($document->path), 404);

        return $disk->response($document->path);
    }
}
