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
        $this->assertStringContainsString('focus:ring-2', $html);     // a11y: every variant is focusable
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
        $this->assertStringContainsString('bg-error', Blade::render('<x-button variant="danger">x</x-button>'));
        $this->assertStringContainsString('bg-warning', Blade::render('<x-button variant="warning">x</x-button>'));
        $this->assertStringContainsString('border-error/40', Blade::render('<x-button variant="danger-soft">x</x-button>'));
        $this->assertStringContainsString('border-brand', Blade::render('<x-button variant="outline">x</x-button>'));
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
