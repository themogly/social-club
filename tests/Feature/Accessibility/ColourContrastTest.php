<?php

namespace Tests\Feature\Accessibility;

use Tests\TestCase;

/**
 * Prompt 98 — the semantic colour tokens must clear WCAG 2.1 AA (4.5:1 for normal text) in BOTH schemes,
 * on BOTH surfaces, AND on the /10 tinted badge/banner backgrounds those states use. Each token failed in
 * only one scheme, so the values are per-scheme (see resources/css/app.css). The maths is deterministic —
 * this is the permanent regression guard, read straight from the stylesheet so a token edit is checked.
 */
class ColourContrastTest extends TestCase
{
    private const SURFACE = '#ffffff';

    private const SURFACE_ALT = '#f8fafc';

    private const DARK = '#0f172a';

    private const AA = 4.5;

    /** @return array<string, array{light: string, dark: string}> */
    private function tokens(): array
    {
        // Tokens moved to the shared partial (prompt 100) — the ONE source read by both app.css and the panel theme.
        $css = (string) file_get_contents(base_path('resources/css/tokens.css'));
        $out = [];
        foreach (['warning', 'success', 'error'] as $token) {
            preg_match_all('/--color-'.$token.':\s*(#[0-9a-fA-F]{6})/', $css, $m);
            // The FIRST match is the @theme (light) value; the next is the dark override.
            $this->assertGreaterThanOrEqual(2, count($m[1]), "Expected a light and a dark value for --color-{$token}.");
            $out[$token] = ['light' => strtolower($m[1][0]), 'dark' => strtolower($m[1][1])];
        }

        return $out;
    }

    private function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = $this->rgb($hex);
        $lin = fn (int $c): float => ($v = $c / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    private function ratio(string $fg, string $bg): float
    {
        $a = $this->relativeLuminance($fg) + 0.05;
        $b = $this->relativeLuminance($bg) + 0.05;

        return round(max($a, $b) / min($a, $b), 2);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');

        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
    }

    /** Alpha-composite a colour over a background (e.g. bg-warning/10 = token at 0.1 over the surface). */
    private function over(string $fg, string $bg, float $alpha): string
    {
        $f = $this->rgb($fg);
        $b = $this->rgb($bg);
        $o = [];
        for ($i = 0; $i < 3; $i++) {
            $o[$i] = (int) round($f[$i] * $alpha + $b[$i] * (1 - $alpha));
        }

        return sprintf('#%02x%02x%02x', $o[0], $o[1], $o[2]);
    }

    public function test_each_semantic_token_clears_aa_in_its_scheme_on_both_surfaces_and_the_tint(): void
    {
        foreach ($this->tokens() as $name => $v) {
            // LIGHT value on both light surfaces + the /10 tint over each.
            foreach ([self::SURFACE, self::SURFACE_ALT] as $bg) {
                $this->assertGreaterThanOrEqual(self::AA, $this->ratio($v['light'], $bg), "warning/{$name} light {$v['light']} on {$bg}");
                $this->assertGreaterThanOrEqual(self::AA, $this->ratio($v['light'], $this->over($v['light'], $bg, 0.1)), "{$name} light on its /10 tint over {$bg}");
            }

            // DARK value on the dark surface + its /10 tint.
            $this->assertGreaterThanOrEqual(self::AA, $this->ratio($v['dark'], self::DARK), "{$name} dark {$v['dark']} on dark");
            $this->assertGreaterThanOrEqual(self::AA, $this->ratio($v['dark'], $this->over($v['dark'], self::DARK, 0.1)), "{$name} dark on its /10 tint");
        }
    }

    public function test_the_counter_operator_warning_passes_aa_in_both_schemes(): void
    {
        // "Sin operador identificado" is .text-warning — the DEFAULT state of every counter screen, so the
        // most-seen text in the product. It renders on surface-alt (light) and #0f172a (dark).
        $warning = $this->tokens()['warning'];

        $this->assertGreaterThanOrEqual(self::AA, $this->ratio($warning['light'], self::SURFACE_ALT), 'operator warning on light');
        $this->assertGreaterThanOrEqual(self::AA, $this->ratio($warning['dark'], self::DARK), 'operator warning on dark');
    }

    public function test_the_passing_tokens_are_not_regressed(): void
    {
        // Brand blue must stay ≥ AA on both light surfaces (it was passing — do not break it while fixing others).
        $this->assertGreaterThanOrEqual(self::AA, $this->ratio('#2563eb', self::SURFACE));
        $this->assertGreaterThanOrEqual(self::AA, $this->ratio('#2563eb', self::SURFACE_ALT));
    }

    public function test_the_language_switchers_meet_the_minimum_target_size(): void
    {
        // WCAG 2.2 Target Size (Minimum) 24×24 — asserted at the markup level (min-w ≥ 28px, min-h ≥ 24px),
        // since there is no browser here to measure rendered pixels.
        foreach ([
            'resources/views/components/layouts/socio.blade.php',
            'resources/views/livewire/locale-switcher.blade.php',
        ] as $file) {
            $markup = (string) file_get_contents(base_path($file));
            $this->assertStringContainsString('min-h-[1.5rem]', $markup, "{$file} locale button needs a ≥24px min height");
            $this->assertStringContainsString('min-w-[1.75rem]', $markup, "{$file} locale button needs a ≥24px min width");
        }
    }
}
