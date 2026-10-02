<?php

namespace App\Support\Guides;

use Closure;
use Illuminate\Support\Str;
use League\CommonMark\Extension\Table\TableExtension;

/**
 * Prompt 353 — a guide's Markdown as HTML, for the web page and the PDF alike: CommonMark with the GFM tables extension,
 * **raw HTML escaped** (a `<script>` in a guide is shown as text, never run) and unsafe links refused. The `##` headings
 * get ids and become the contents list; each image's `src` becomes the authorised, hashed image URL the caller builds.
 */
final class GuideRenderer
{
    /**
     * @param  Closure(string $file): string  $imageUrl  the URL for one of this guide's image files
     * @return array{html: string, toc: list<array{id: string, title: string}>}
     */
    public static function render(Guide $guide, Closure $imageUrl, bool $lazy = true): array
    {
        $html = (string) Str::markdown($guide->body, ['html_input' => 'escape', 'allow_unsafe_links' => false], [new TableExtension]);

        $toc = [];
        $html = (string) preg_replace_callback('/<h2>(.*?)<\/h2>/s', function (array $m) use (&$toc): string {
            $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5);
            $id = Str::slug($title) ?: 'seccion-'.(count($toc) + 1);
            $toc[] = ['id' => $id, 'title' => $title];

            return '<h2 id="'.e($id).'">'.$m[1].'</h2>';
        }, $html);

        // The title is the page's own heading; a leading `# Title` in the source would print it twice.
        $html = (string) preg_replace('/\A\s*<h1>.*?<\/h1>\s*/s', '', $html);

        $html = (string) preg_replace_callback('/<img src="([^"]*)" alt="([^"]*)"\s*\/?>/', function (array $m) use ($guide, $imageUrl, $lazy): string {
            $src = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            $prefix = 'img/'.$guide->slug.'/';
            if (! str_starts_with($src, $prefix) || GuideLibrary::imagePath($guide->slug, substr($src, strlen($prefix))) === null) {
                return ''; // only this guide's own images, from the repo — never a remote or relative-escape src
            }

            return '<img src="'.e($imageUrl(substr($src, strlen($prefix)))).'" alt="'.$m[2].'"'.($lazy ? ' loading="lazy" decoding="async"' : '').' data-guide-image>';
        }, $html);

        return ['html' => $html, 'toc' => $toc];
    }
}
