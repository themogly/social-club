<?php

namespace App\Support;

use App\Models\Article;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The public URL of an article's first image, or null (prompt 230).
 *
 * Lifted out of `BarPos` when the POS's bar catalogue needed the same answer: the two screens now render the
 * same `x-counter.article-card`, and a card that shows a thumbnail on one screen and not on the other because
 * only one of them knew how to build the URL is exactly the divergence this branch exists to end.
 *
 * Null on a missing or unreadable path — a thumbnail is a nicety and must never take a counter screen down.
 */
class ArticleImage
{
    public static function url(Article $article): ?string
    {
        $first = data_get($article->images, '0');

        if (! is_string($first) || $first === '') {
            return null;
        }

        try {
            return Storage::disk('public')->url($first);
        } catch (Throwable) {
            return null;
        }
    }
}
