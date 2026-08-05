<?php

namespace Tests\Feature\Socio;

use App\Models\Member;
use App\Support\SocioForm;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Prompt 156 — the member PWA's newest screens shipped with hand-copied form-field classes that had lost
 * their padding, border width and focus ring, and a notifications row that rendered a channel's raw key.
 * These lock in the single shared definition and the drift-proof channel labels.
 */
class MemberPwaFormStyleTest extends TestCase
{
    /**
     * The shared definition must carry the three properties the message form lost: horizontal AND vertical
     * padding, a real border WIDTH (not only a colour), and a focus-ring WIDTH (not only a colour). A colour
     * without a width renders as nothing — which is exactly how the bug was invisible in review.
     */
    public function test_the_shared_field_definition_has_padding_a_border_and_a_visible_focus_ring(): void
    {
        $field = SocioForm::FIELD;

        $this->assertStringContainsString('px-3', $field, 'horizontal padding');
        $this->assertStringContainsString('py-2.5', $field, 'vertical padding');
        $this->assertMatchesRegularExpression('/(?:^|\s)border(?:\s|$)/', $field, 'a border WIDTH utility, not only border-<colour>');
        $this->assertStringContainsString('focus:ring-2', $field, 'a focus-ring WIDTH, not only ring-<colour>');
    }

    /** Both components emit the shared definition, so any form that uses them inherits it and cannot drift. */
    public function test_the_socio_input_and_textarea_render_the_shared_definition(): void
    {
        $input = Blade::render('<x-socio.input type="text" name="t" />');
        $textarea = Blade::render('<x-socio.textarea name="t"></x-socio.textarea>');

        foreach (['px-3', 'py-2.5', 'focus:ring-2'] as $needle) {
            $this->assertStringContainsString($needle, $input, "input is missing {$needle}");
            $this->assertStringContainsString($needle, $textarea, "textarea is missing {$needle}");
        }

        // A caller's own class is merged, not replaced — the shared style always survives.
        $this->assertStringContainsString('mt-1', Blade::render('<x-socio.input name="t" class="mt-1" />'));
    }

    /**
     * Coverage guard against the ORIGINAL defect returning: every text-like control on a member-PWA screen
     * must reach the shared definition — via the component, the SocioForm::FIELD constant, or the $input alias
     * that equals it — never a hand-copied class string. This is what would have caught the message form.
     */
    public function test_every_member_pwa_text_control_uses_the_shared_definition(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views/socio/*.blade.php')) as $view) {
            $src = file_get_contents($view);
            preg_match_all('/<(?:input\b[^>]*\btype="(?:text|email|tel|date|number)"[^>]*|textarea\b[^>]*|select\b[^>]*)>/', $src, $matches);

            foreach ($matches[0] as $tag) {
                if (! str_contains($tag, 'class="')) {
                    continue; // no class at all (e.g. the hidden honeypot) — nothing to drift
                }

                $usesShared = str_contains($tag, 'SocioForm::FIELD')
                    || str_contains($tag, '{{ $input }}');

                if (! $usesShared) {
                    $offenders[] = basename($view).': '.preg_replace('/\s+/', ' ', substr($tag, 0, 80));
                }
            }
        }

        $this->assertSame([], $offenders, 'these PWA controls carry a hand-written class instead of the shared definition');
    }

    /**
     * Every push channel a member can opt out of resolves to real copy — none falls through to its raw key.
     * Asserting against the constant means a channel added tomorrow without a label fails the build here,
     * rather than shipping as a row labelled "new_message" (which is exactly what happened).
     */
    public function test_every_push_channel_has_a_member_facing_label(): void
    {
        $this->assertNotEmpty(Member::PUSH_CHANNELS);

        foreach (Member::PUSH_CHANNELS as $channel) {
            $this->assertNotSame(
                $channel,
                Member::pushChannelLabel($channel),
                "push channel [{$channel}] has no label and would render its raw key to a member",
            );
        }
    }
}
