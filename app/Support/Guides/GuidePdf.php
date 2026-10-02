<?php

namespace App\Support\Guides;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Prompt 353 — a guide as an A4 PDF, from the same Markdown as its page (dompdf). Cached on the local disk under the
 * guide's content hash ({@see Guide::contentHash()}): the first download after a change renders it, every later one is
 * the stored file. Older renders of that guide are removed when a new one is written.
 */
final class GuidePdf
{
    public static function bytes(Guide $guide): string
    {
        $disk = Storage::disk('local');
        $path = 'guides/pdf/'.$guide->slug.'-'.$guide->contentHash().'.pdf';

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        // Images by file path inside the app (dompdf's chroot is the app's base path), never fetched over HTTP.
        $page = GuideRenderer::render($guide, fn (string $file): string => (string) GuideLibrary::imagePath($guide->slug, $file), lazy: false);
        $bytes = Pdf::loadView('guides.pdf', ['guide' => $guide, 'html' => $page['html']])->setPaper('a4')->output();

        foreach ($disk->files('guides/pdf') as $old) {
            if (preg_match('/^'.preg_quote($guide->slug, '/').'-[0-9a-f]{16}\.pdf$/', basename($old)) === 1) {
                $disk->delete($old);
            }
        }
        $disk->put($path, $bytes);

        return $bytes;
    }
}
