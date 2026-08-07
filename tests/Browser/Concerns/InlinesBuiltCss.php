<?php

namespace Tests\Browser\Concerns;

/**
 * Inline the stylesheets a rendered page ACTUALLY LINKS, so a `file://` capture looks like the page.
 *
 * **Found by prompt 206, and it had been quietly wrong.** Both counter harnesses inlined
 * `glob(public_path('build/assets/*.css'))` — every stylesheet in the build, concatenated. That pulls in
 * Filament's `theme-*.css`, which a counter screen never loads, and which ends with its own copy of the
 * Tailwind utility layer. Its `.hidden{display:none}` therefore landed AFTER app.css's
 * `@media (width>=64rem){.lg\:inline{display:inline}}` — equal specificity, later wins — so every
 * `hidden lg:inline` label in the top bar was forced off **at every width**.
 *
 * What that cost: `measure-topbar.mjs` has been measuring an icon-only row and reporting PASS, when the row
 * a 1180px tablet really renders is the labelled one — the wider of the two, and the only one that could
 * overlap. Same defect class as the two 205 recorded about this script: an instrument that reports on
 * something other than the thing.
 *
 * Inlining exactly the `<link>`s the page emitted is faithful by construction, and stays faithful if the
 * build splits or renames a chunk.
 */
trait InlinesBuiltCss
{
    protected function inlineBuiltCss(string $html): string
    {
        return (string) preg_replace_callback(
            '#<link[^>]*href="[^"]*/build/(assets/[^"]+\.css)"[^>]*>#',
            function (array $match): string {
                $path = public_path('build/'.$match[1]);

                return is_file($path) ? '<style>'.file_get_contents($path).'</style>' : '';
            },
            $html,
        );
    }
}
