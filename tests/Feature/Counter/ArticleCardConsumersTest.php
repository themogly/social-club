<?php

namespace Tests\Feature\Counter;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Prompt 230 — ONE article card, and a guard that keeps it one.
 *
 * The owner, with both screens side by side: *"make the standalone bar POS the same design as the other
 * one."* They were two staff surfaces selling the same articles from the same sede, disagreeing about
 * density (68px row against 60px), about facts (an exact stock count on one, none on the other) and about
 * whether a sold-out article exists at all (visible-and-disabled here, excluded from the feed there).
 *
 * This is the **fifth** application of the same answer, and the pattern is now the project's house style:
 * `OpensMemberships` (203→211), the MRZ partial (179→215), the application field list (210→215), the
 * signature canvas (113→220), the login preamble (223→226). Each shipped green with one consumer, because a
 * green suite proves a unit WORKS and never that everything which should use it DOES. So the card is a
 * component and this test iterates its consumers: a third catalogue hand-rolling a fourth card fails here.
 */
class ArticleCardConsumersTest extends TestCase
{
    private const COMPONENT = 'resources/views/components/counter/article-card.blade.php';

    /** Every screen that sells an article, and which basket a tap fills. */
    private const CONSUMERS = [
        'resources/views/livewire/counter/bar-pos.blade.php' => 'addArticle',
        'resources/views/livewire/counter/dispensary-pos.blade.php' => 'addBarItem',
    ];

    /** @return array<string, string> relative path => contents */
    private function templates(): array
    {
        $views = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $views[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        return $views;
    }

    public function test_the_card_is_one_component(): void
    {
        $this->assertFileExists(base_path(self::COMPONENT));

        $card = (string) file_get_contents(base_path(self::COMPONENT));
        $this->assertStringContainsString('data-article-card', $card);
        $this->assertStringContainsString('data-article-stock', $card, 'the card no longer states stock');
    }

    /** Every consumer renders it, and passes its OWN action — the shape is shared, the action is not. */
    public function test_every_consumer_renders_the_shared_card(): void
    {
        foreach (self::CONSUMERS as $path => $action) {
            $blade = (string) file_get_contents(base_path($path));

            $this->assertStringContainsString('<x-counter.article-card', $blade, "{$path} no longer renders the shared card");
            $this->assertStringContainsString('action="'.$action.'"', $blade, "{$path} does not pass its own action");
        }
    }

    /**
     * **No screen hand-rolls an article card.**
     *
     * Measured as a rule over every template rather than as a list of the two we know about — that is the
     * difference between a guard and a note.
     */
    public function test_no_screen_hand_rolls_an_article_card(): void
    {
        $offenders = [];

        foreach ($this->templates() as $path => $blade) {
            if ($path === self::COMPONENT) {
                continue;
            }

            foreach (explode("\n", $blade) as $index => $line) {
                // A line that CALLS an add-article action from its own markup is a hand-rolled card. A line
                // that renders the component is not, whatever it names.
                if (str_contains($line, 'x-counter.article-card')) {
                    continue;
                }

                if (preg_match('/wire:click="(addArticle|addBarItem)\(/', $line)) {
                    $offenders[] = $path.':'.($index + 1).' — '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These templates build their own article card instead of using <x-counter.article-card>:',
            '  '.implode("\n  ", $offenders),
            '',
            'One card, every consumer (prompt 230). A second is a second answer to "is this sold out", a',
            'second density and a second thing to forget — which is exactly the divergence the owner reported.',
        ]));
    }

    /** The guard catches one — planted, not assumed. */
    public function test_the_guard_catches_a_planted_card(): void
    {
        $planted = "<div>\n    <button wire:click=\"addArticle('{{ \$a['id'] }}')\">{{ \$a['name'] }}</button>\n</div>\n";

        $offenders = [];
        foreach (explode("\n", $planted) as $index => $line) {
            if (str_contains($line, 'x-counter.article-card')) {
                continue;
            }
            if (preg_match('/wire:click="(addArticle|addBarItem)\(/', $line)) {
                $offenders[] = 'planted.blade.php:'.($index + 1);
            }
        }

        $this->assertSame(['planted.blade.php:2'], $offenders, 'the guard cannot see a hand-rolled card');
    }

    /**
     * 193's rule survives the move: no placeholder glyph, on either layout.
     *
     * Blade COMMENTS are stripped first. These files explain what the placeholder was and why it went, so a
     * reader that matched the raw bytes would fail on the sentence describing the fix — the same trap 215's
     * parity reader, 222's pad guard and 228's touch sweep each fell into. Measure what renders.
     */
    public function test_the_card_has_no_placeholder_glyph(): void
    {
        $rendered = fn (string $path): string => (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path($path)));

        $this->assertStringNotContainsString('🛒', $rendered(self::COMPONENT), 'the tile placeholder came back');

        foreach (array_keys(self::CONSUMERS) as $path) {
            $this->assertStringNotContainsString('🛒', $rendered($path), "{$path} still renders a placeholder glyph");
        }
    }
}
