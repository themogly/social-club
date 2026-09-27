<?php

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Prompt 36 — the shared <x-button>. Locks the canonical per-variant classes and the
 * button/anchor + attribute-passthrough contract, so the hand-rolled per-screen drift
 * can't quietly creep back.
 */
class ButtonComponentTest extends TestCase
{
    public function test_the_default_is_a_primary_button_with_a_visible_focus_ring(): void
    {
        $html = Blade::render('<x-button>Guardar</x-button>');

        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString('type="button"', $html);   // default type when none given
        $this->assertStringContainsString('bg-brand', $html);
        $this->assertStringContainsString('focus-visible:ring-2', $html);     // a11y: a keyboard-visible ring (prompt 272)
        $this->assertStringContainsString('Guardar', $html);
    }

    public function test_href_renders_an_anchor_instead_of_a_button(): void
    {
        $html = Blade::render('<x-button href="/panel" variant="secondary">Ir</x-button>');

        $this->assertStringContainsString('<a', $html);
        $this->assertStringContainsString('href="/panel"', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    public function test_each_variant_maps_to_its_palette_classes(): void
    {
        // Prompt 272 — solid danger/warning paint the FILL tokens (white-safe in both schemes), never the text
        // tokens, and never fade the label on hover.
        foreach (['danger' => 'bg-error-fill', 'warning' => 'bg-warning-fill'] as $variant => $fill) {
            $html = Blade::render('<x-button variant="'.$variant.'">x</x-button>');
            $this->assertStringContainsString($fill.' text-white', $html);
            $this->assertStringNotContainsString('hover:opacity', $html);
        }
        $this->assertStringContainsString('border-error/40', Blade::render('<x-button variant="danger-soft">x</x-button>'));
        $this->assertStringContainsString('border-brand', Blade::render('<x-button variant="outline">x</x-button>'));
    }

    public function test_the_focus_ring_is_full_strength_and_offset_in_both_schemes(): void
    {
        // Prompt 272 (a11y audit): `focus:ring-brand/40` with no offset measured 1.5–2.9:1 — invisible on a fill.
        $html = Blade::render('<x-button>x</x-button>');

        $this->assertStringContainsString('focus-visible:ring-brand ', $html);
        $this->assertStringContainsString('focus-visible:ring-offset-2', $html);
        $this->assertStringContainsString('dark:focus-visible:ring-offset-slate-950', $html);
        $this->assertStringNotContainsString('ring-brand/40', $html);
    }

    public function test_sizes_map_to_their_heights(): void
    {
        $this->assertStringContainsString('h-16', Blade::render('<x-button size="xl">x</x-button>'));
        $this->assertStringContainsString('h-10', Blade::render('<x-button size="sm">x</x-button>'));
    }

    public function test_caller_attributes_pass_through_and_override_the_default_type(): void
    {
        $html = Blade::render('<x-button type="submit" wire:click="commit" class="w-full">Go</x-button>');

        $this->assertStringContainsString('type="submit"', $html);   // caller wins over the type=button default
        $this->assertStringContainsString('wire:click="commit"', $html);
        $this->assertStringContainsString('w-full', $html);          // layout class merged, not dropped
        $this->assertStringContainsString('bg-brand', $html);        // ...and the variant classes stay
    }
}
