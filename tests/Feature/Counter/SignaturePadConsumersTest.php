<?php

namespace Tests\Feature\Counter;

use Tests\TestCase;

/**
 * Prompt 220 — the guard that makes ONE signature pad stay one signature pad.
 *
 * Prompt 113 built a working pad and left it inline in `dispensary-pos.blade.php`. That is this project's
 * most-repeated defect, hit four times now — `OpensMemberships` (203 wired one consumer, 211 the rest), the
 * MRZ partial (179 the public form, 215 the staff one), the application field list (210 two hand-written
 * copies, 215 one declaration) and this. Every one shipped `composer check` green, because a green suite
 * proves the unit WORKS, never that everything which should use it DOES.
 *
 * So the pad is `x-counter.signature-pad` and this test asserts the RULE rather than today's list: a canvas
 * used for a signature may exist in exactly one file, and every screen that takes one goes through it. A
 * fifth consumer hand-rolling a fifth canvas fails here.
 */
class SignaturePadConsumersTest extends TestCase
{
    private const COMPONENT = 'resources/views/components/counter/signature-pad.blade.php';

    /** Every screen that takes a signature, and what it is signing. */
    private const CONSUMERS = [
        'resources/views/livewire/counter/dispensary-pos.blade.php' => 'the dispensation (prompt 113)',
        'resources/views/socio/application.blade.php' => 'the applicant\'s own consent (prompt 220)',
        'resources/views/livewire/counter/partials/alta-staff-form.blade.php' => 'the consent on the staff route (prompt 220)',
    ];

    /** @return array<int, string> every blade template in the app */
    private function templates(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** The component exists and is the thing with the canvas in it. */
    public function test_the_pad_is_one_component(): void
    {
        $this->assertFileExists(base_path(self::COMPONENT));
        $this->assertStringContainsString('data-signature-canvas', (string) file_get_contents(base_path(self::COMPONENT)));
    }

    /**
     * **No second canvas.** Measured as a rule over every template, not as a list of the ones we know about —
     * that is the difference between a guard and a note.
     */
    public function test_no_screen_hand_rolls_its_own_signature_canvas(): void
    {
        $offenders = [];

        foreach ($this->templates() as $path) {
            $relative = str_replace(base_path().'/', '', $path);

            if ($relative === self::COMPONENT) {
                continue;
            }

            $blade = (string) file_get_contents($path);

            // A template that RENDERS a canvas, not one that mentions the hook. Prompt 222's close guard has
            // to ask "is there a drawn signature in this modal?", which means naming the hook in a selector —
            // and the first version of this reader counted that as a second pad. The rule was always about
            // rendering; the reader now measures it, by looking for the element rather than the string.
            if (preg_match('/<canvas[^>]*(data-signature-canvas|x-ref="pad")/s', $blade)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These templates hand-roll a signature canvas instead of using <x-counter.signature-pad>: '.implode(', ', $offenders).'.',
            '',
            'One pad, every consumer (prompt 220). A second canvas is a second set of touch behaviour, a second',
            'clear/save contract and a second thing to forget when the vault path changes.',
        ]));
    }

    /** Every known consumer still renders it — a consumer that quietly loses its pad fails here too. */
    public function test_every_consumer_renders_the_shared_pad(): void
    {
        foreach (self::CONSUMERS as $path => $what) {
            $blade = (string) file_get_contents(base_path($path));

            $this->assertStringContainsString(
                '<x-counter.signature-pad',
                $blade,
                "{$path} no longer signs {$what} through the shared pad",
            );
        }
    }

    /**
     * The mechanic follows the HOST, not the artefact.
     *
     * The applicant's own form is a plain POST with no component behind it, so its drawing travels in a hidden
     * input. The dispensation and the staff-typed alta both sit inside Livewire components, so theirs goes
     * straight to a method. Getting this backwards is silent — a `form`-mode pad inside Livewire posts nothing
     * and a `livewire`-mode pad on the public form calls `$wire` on a page that has none.
     */
    public function test_each_consumer_uses_the_mechanic_its_host_can_carry(): void
    {
        $public = $this->padTag((string) file_get_contents(base_path('resources/views/socio/application.blade.php')));
        $this->assertStringContainsString('mode="form"', $public, 'the public form has no Livewire host — its signature must post with the form');

        foreach ([
            'resources/views/livewire/counter/dispensary-pos.blade.php',
            'resources/views/livewire/counter/partials/alta-staff-form.blade.php',
        ] as $path) {
            $pad = $this->padTag((string) file_get_contents(base_path($path)));

            $this->assertStringContainsString('capture=', $pad, "{$path} lost its Livewire capture method");
            $this->assertStringNotContainsString('mode="form"', $pad, "{$path} has a Livewire host — the form mechanic would post nothing");
        }
    }

    private function padTag(string $blade): string
    {
        preg_match('/<x-counter\.signature-pad.*?\/?>/s', $blade, $matches);

        $this->assertNotEmpty($matches, 'the shared pad is not rendered here');

        return $matches[0];
    }
}
