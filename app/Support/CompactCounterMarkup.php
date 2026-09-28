<?php

namespace App\Support;

use Livewire\Component;

use function Livewire\on;

/**
 * Prompt 293 — a counter screen's HTML leaves without its template indentation.
 *
 * Every Livewire response carries the markup it renders, and the counter's templates are deeply nested: at club size a
 * quarter of a basket tap's response was the leading whitespace of lines no browser displays. A run of whitespace becomes one
 * character (a newline if it held one) and a line loses its indentation — the page renders identically — and `<pre>` / `<textarea>` content, where
 * whitespace IS the content, is left exactly as written. Counter components only (tablet-first, re-rendered per tap);
 * the panel is Filament's markup and stays as it is.
 */
class CompactCounterMarkup
{
    public static function register(): void
    {
        $compact = fn (Component $component) => self::applies($component) ? fn (string $html): string => self::strip($html) : null;

        on('render', $compact);
        on('renderIsland', $compact);
    }

    public static function strip(string $html): string
    {
        return (string) preg_replace_callback(
            '~<(pre|textarea)\b[^>]*>.*?</\1>|\s{2,}~is',
            fn (array $m): string => ($m[1] ?? '') !== '' ? $m[0] : (str_contains($m[0], "\n") ? "\n" : ' '),
            $html,
        );
    }

    private static function applies(Component $component): bool
    {
        return str_starts_with($component::class, 'App\\Livewire\\Counter\\');
    }
}
