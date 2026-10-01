<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CounterHome;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Prompt 272 — the pre-live design + accessibility audits' Phase 2/3 behaviours, pinned.
 *
 * Every item here is a rule the audit found broken in the browser (`audits/reports/2026-09-pre-live-*.md`). This
 * repo has no browser in CI, so each is asserted as the rendered contract that produces the behaviour; the
 * Playwright harness `tests/Browser/shoot-pre-live-design-fixes.mjs` is where they were looked at.
 */
class PreLiveA11yFixesTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->location = Location::factory()->create(['organisation_id' => $org->id]);

        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }
    }

    // --- Overlays: focus in, focus back, and the back gesture closes them ------------------------------------

    public function test_the_sign_up_modal_takes_focus_and_closes_on_back_through_its_guard(): void
    {
        $html = Livewire::test(MembershipCounter::class)->call('toggleAlta')->html();

        $this->assertStringContainsString("history.pushState({ altaModal: true }, '')", $html, 'no history entry, so Back leaves the page');
        $this->assertStringContainsString("window.addEventListener('popstate', this.popHandler)", $html);
        // Back runs the SAME dirty guard as ✕ / Escape, and a declined confirm restores the entry.
        $this->assertMatchesRegularExpression('/onPopState\(\) \{\s*if \(this\.closing\) return\s*if \(\(this\.serverSaysDirty\(\) \|\| this\.domSaysDirty\(\)\)/', $html);
        // Initial focus on the modal's title; focus returns to the trigger when the modal leaves the DOM.
        $this->assertMatchesRegularExpression('/id="alta-modal-title" x-ref="altaTitle" tabindex="-1"/', $html);
        $this->assertStringContainsString("document.querySelector('[data-alta-toggle]')?.focus(", $html);
    }

    public function test_the_manual_amount_dialog_takes_focus_and_closes_on_back(): void
    {
        $html = Livewire::test(BarPos::class)->html();

        // Prompt 331 — the modal is one shared partial; the trigger asks it to open, and it keeps its trigger for focus.
        $this->assertStringContainsString("@click=\"\$dispatch('manual-line-open')\"", $html);
        $this->assertStringContainsString('x-on:manual-line-open.window="openMisc()"', $html);
        $this->assertStringContainsString('this.miscTrigger = document.activeElement', $html);
        $this->assertStringContainsString("history.pushState({ barMisc: true }, '')", $html);
        $this->assertStringContainsString("document.getElementById('misc-desc')?.focus()", $html);
        $this->assertStringContainsString('x-on:popstate.window="if (showMisc)', $html);
        $this->assertStringNotContainsString('@click="showMisc = true"', $html);
    }

    public function test_the_camera_and_photo_overlays_take_part_in_history(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("overlayHistory.push('camera')", $js);
        $this->assertStringContainsString("overlayHistory.push('photo')", $js);
        // Camera, photo, (prompt 313) `historyDialog` and (prompt 343) `topBarMenu` — each removes its listener in destroy().
        $this->assertSame(4, substr_count($js, "window.addEventListener('popstate', this.onPopState)"));
        $this->assertSame(4, substr_count($js, "window.removeEventListener('popstate', this.onPopState)") - 1, 'each listener is removed (historyDialog also removes it when Back fires)');
    }

    // --- The staff wizard's errors are tied to their fields ---------------------------------------------------

    public function test_a_failed_step_marks_its_fields_invalid_and_describes_them(): void
    {
        $html = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')->set('altaPhoto', UploadedFile::fake()->image('foto.jpg'))
            ->call('altaNext')
            ->html();

        $this->assertMatchesRegularExpression('/id="alta-first-name"[^>]*aria-required="true"[^>]*aria-invalid="true"[^>]*aria-describedby="altaForm\.first_name-error"/', $html);
        $this->assertMatchesRegularExpression('/<p id="altaForm\.first_name-error" role="alert"/', $html);
        // …and focus goes to the first invalid field.
        $this->assertStringContainsString('data-alta-error-focus', $html);
    }

    public function test_the_wizards_file_fields_are_branded_and_translated(): void
    {
        $html = Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('toggleStaffAltaForm')->set('altaPhoto', UploadedFile::fake()->image('foto.jpg'))->html();

        // A visually hidden input inside the shared button label — never the browser's "Choose File".
        $this->assertMatchesRegularExpression('/<input id="alta-photo" type="file" class="sr-only"/', $html);
        $this->assertStringContainsString(e(__('Elegir archivo')), $html);
        $this->assertStringContainsString(e(__('Ningún archivo')), $html);
    }

    // --- Named, stateful controls ---------------------------------------------------------------------------

    public function test_toggles_say_which_option_is_selected_and_the_keypad_backspace_has_a_name(): void
    {
        $pos = (string) file_get_contents(resource_path('views/livewire/counter/dispensary-pos.blade.php'));
        $fee = (string) file_get_contents(resource_path('views/livewire/counter/partials/inline-fee.blade.php'));
        $bar = (string) file_get_contents(resource_path('views/livewire/counter/bar-pos.blade.php'));
        // Prompt 282 moved the manual-lote chip into its own component.
        $chip = (string) file_get_contents(resource_path('views/components/counter/batch-chip.blade.php'));

        // Prompt 292 — the Gramos/€ switch is client-side now; it still says which option is selected.
        $this->assertStringContainsString('@click="setMode(true)" x-bind:aria-pressed=', $pos);
        $this->assertStringContainsString('@click="setMode(false)" x-bind:aria-pressed=', $pos);
        $this->assertStringContainsString("wire:click=\"selectBatch('{{ \$batch->id }}')\" aria-pressed=", $chip);
        // Prompt 293 — the filter chips are the browser's; each still says, live, whether it is the one selected.
        $this->assertStringContainsString("x-on:click=\"filter('{{ \$axis }}', @js(\$value))\"\n                                                aria-pressed=", $pos);
        $this->assertStringContainsString('x-bind:aria-pressed="{{ $state }} === @js($value)', $pos);
        $this->assertStringContainsString("wire:click=\"\$set('feeMethod', 'CASH')\" aria-pressed=", $fee);
        $this->assertStringContainsString("x-bind:aria-pressed=\"category.bar === @js(\$value) ? 'true' : 'false'\"", $bar);
        $this->assertStringContainsString("@click=\"back()\" aria-label=\"{{ __('Retroceso') }}\"", $pos);
    }

    public function test_the_tender_totals_are_a_polite_live_region_on_both_screens(): void
    {
        foreach (['dispensary-pos', 'bar-pos'] as $view) {
            $blade = (string) file_get_contents(resource_path("views/livewire/counter/{$view}.blade.php"));
            $this->assertMatchesRegularExpression('/<dl data-tender-summary aria-live="polite" class="[^"]*dark:bg-slate-950/', $blade, $view);
        }
    }

    public function test_the_signature_pad_is_named_and_announces_a_save(): void
    {
        $html = Blade::render('<x-counter.signature-pad mode="form" name="signature" />');

        $this->assertMatchesRegularExpression('/data-signature-canvas\s+.*?role="img"\s+aria-label="[^"]+"/s', $html);
        $this->assertStringContainsString('<p role="status"', $html);
    }

    // --- The hub --------------------------------------------------------------------------------------------

    public function test_hub_alerts_are_finger_targets_with_a_spoken_severity_and_the_help_has_a_chevron(): void
    {
        $blade = (string) file_get_contents(resource_path('views/livewire/counter/counter-home.blade.php'));

        $this->assertStringContainsString("'mt-2 flex min-h-11 items-center gap-2 rounded-lg px-2 py-1.5 text-sm'", $blade);
        $this->assertStringContainsString('<span class="sr-only">{{ $severityWord }}: </span>', $blade);
        $this->assertStringContainsString('group-open:rotate-180', $blade);

        Livewire::test(CounterHome::class)->assertOk();
    }

    // --- Palette discipline -------------------------------------------------------------------------------------

    public function test_no_counter_view_draws_an_os_emoji_as_an_icon(): void
    {
        // The counter's icons are one outline set in currentColor (x-counter.icon). Blade comments may still NAME an
        // emoji (the history is worth keeping); rendered markup may not draw one.
        $emoji = '/[\x{1F300}-\x{1FAFF}\x{2B1C}\x{25A6}\x{2630}\x{26A0}\x{26D4}]/u';
        $finder = (new Finder)->files()->in([resource_path('views/livewire/counter'), resource_path('views/components/counter')])->name('*.blade.php');

        foreach ($finder as $file) {
            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
            $this->assertDoesNotMatchRegularExpression($emoji, $markup, $file->getRelativePathname().' draws an emoji icon');
        }
    }

    public function test_the_one_status_badge_renders_on_every_member_card(): void
    {
        foreach (['check-in-screen', 'membership-counter', 'partials/member-cart-summary'] as $view) {
            $blade = (string) file_get_contents(resource_path("views/livewire/counter/{$view}.blade.php"));
            $this->assertStringContainsString("@include('livewire.counter.partials.member-status-badge'", $blade, $view);
        }

        Livewire::test(DispensaryPos::class)->assertOk();
    }
}
