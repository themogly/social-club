<?php

namespace Tests\Feature\Design;

use Tests\TestCase;

/**
 * Prompt 345 — the panel's small controls at a thumb's size on a touch screen, desktop unchanged. The browser proof
 * (`tests/Browser/prove-345-thumb-targets.mjs`) measures it on every panel page; this pins the rules it depends on so
 * `composer check` notices if one is dropped.
 */
class ThumbSizedPanelTargetsTest extends TestCase
{
    private function coarseBlock(): string
    {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
        $at = strpos($css, '@media (pointer: coarse)');
        $this->assertNotFalse($at, 'no coarse-pointer block in the panel theme');

        return substr($css, (int) $at);
    }

    public function test_the_reported_controls_get_a_44_px_tap_area_on_touch_only(): void
    {
        $block = $this->coarseBlock();

        foreach (['[data-locale-switch] button', '.fi-ta-filter-indicators .fi-badge-delete-btn', '.fi-link::after', '.fi-badge-delete-btn::after'] as $selector) {
            $this->assertStringContainsString($selector, $block, "{$selector} is not enlarged on touch");
        }
        $this->assertStringContainsString('width: max(100%, 44px);', $block);
        $this->assertStringContainsString('height: max(100%, 44px);', $block);
        $this->assertStringContainsString('min-height: 44px;', $block);
    }

    public function test_the_language_switch_carries_its_hook(): void
    {
        $view = (string) file_get_contents(resource_path('views/livewire/locale-switcher.blade.php'));

        $this->assertStringContainsString('data-locale-switch', $view);
        // The desktop sizes stay as they were (the pin): the classes that set them are untouched.
        $this->assertStringContainsString('min-h-[1.5rem] min-w-[1.75rem]', $view);
    }

    public function test_our_own_small_controls_opt_in(): void
    {
        $this->assertStringContainsString('data-touch-target', (string) file_get_contents(resource_path('views/filament/help-menu.blade.php')));
        $this->assertStringContainsString('data-touch-target', (string) file_get_contents(resource_path('views/filament/pages/dashboard.blade.php')));
        $this->assertStringContainsString('pointer-coarse:min-h-11', (string) file_get_contents(resource_path('views/filament/pages/manual.blade.php')));
    }
}
