<?php

namespace App\Support;

use App\Models\DocumentAccessLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The five prompt-32 protections for streaming a private-disk Article-9 file, extracted so the member
 * document, the member photo and the POS signature endpoints share ONE implementation rather than three
 * near-copies (prompt 113): (1) the route's `signed` middleware enforces expiry; (2) the URL is bound to the
 * issuing user (`u`), so a leaked/replayed URL is refused for another session; (3) `$authorize` throws on a
 * permission/ownership failure; (4) EVERY view is access-logged; (5) the ciphertext is decrypted only here.
 */
class VaultStream
{
    /**
     * @param  callable(): mixed  $authorize  Throws (Gate::authorize) when the actor may not view.
     * @param  array{member_document_id?: ?string, subject_type?: ?string, subject_id?: ?string}  $logSubject
     */
    public static function respond(Request $request, string $path, callable $authorize, array $logSubject): Response
    {
        abort_unless((string) $request->query('u') === (string) $request->user()?->getKey(), 403);

        $authorize();

        abort_unless($path !== '' && Storage::disk(DocumentVault::DISK)->exists($path), 404);

        DocumentAccessLog::create(array_merge([
            'actor_id' => $request->user()?->getKey(),
            'viewed_at' => now(),
            'ip' => $request->ip(),
        ], $logSubject));

        return response(DocumentVault::get($path), 200, [
            'Content-Type' => DocumentVault::mimeFor($path),
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
