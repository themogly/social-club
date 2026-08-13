<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 231 — autofill is suppressed or guided by WHOSE data the form holds.
 *
 * The owner's screenshot, on the staff wizard's contact step in dark mode: Email, Phone and Address painted
 * white by Chrome with **his own address** in them. The paint was the symptom; the data was the hazard — the
 * operator's contact details, one tap from being saved as a new member's.
 *
 * So the split is not "turn autofill off". It is:
 *
 *   · the STAFF wizard types somebody ELSE's details → suppress
 *   · the applicant's own form, and the tablet handed to them, is the person typing their OWN → guide, with
 *     the correct tokens, because there autofill is a kindness
 *
 * **Playwright cannot trigger real Chrome autofill** — it has no saved profile and no way to invoke the UA's
 * filler — so the behaviour itself is not measurable here. The owner's screenshot is the observed case; these
 * are the mechanism pins: the attributes that decide whether the browser offers, and the CSS that decides
 * what it looks like when it does.
 */
class AutofillBelongsToWhoseDataItIsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        return $user;
    }

    /** The wizard's own markup, on the step that holds each field. */
    private function wizardHtml(int $step): string
    {
        $component = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->set('altaForm.first_name', 'Lucía')
            ->set('altaForm.last_name', 'García')
            ->set('altaForm.email', 'lucia@example.es')
            ->set('altaForm.date_of_birth', now()->subYears(30)->format('Y-m-d'))
            ->set('altaForm.document_type', 'DNI')
            ->set('altaForm.document_number', '12345678Z');

        for ($i = 1; $i < $step; $i++) {
            $component->call('altaNext');
        }

        return $component->html();
    }

    // --- Suppressed where the data is somebody else's ---------------------------------------------

    /** **No identity or contact field on the staff wizard invites the browser to fill it.** */
    public function test_the_staff_wizard_suppresses_autofill(): void
    {
        $this->operator();

        foreach ([1 => ['alta-first-name', 'alta-last-name', 'alta-doc-number'], 2 => ['alta-email-staff', 'alta-phone', 'alta-address']] as $step => $ids) {
            $html = $this->wizardHtml($step);

            foreach ($ids as $id) {
                preg_match('/<input[^>]*id="'.$id.'"[^>]*>/', $html, $m);
                $this->assertNotEmpty($m, "{$id} is not on step {$step}");

                $this->assertStringContainsString('data-no-autofill', $m[0], "{$id} does not mark itself as no-autofill");
                $this->assertMatchesRegularExpression('/autocomplete="(off|new-[a-z-]+)"/', $m[0], "{$id} invites the browser to fill it with somebody else's data");
            }
        }
    }

    /** …and no field on it carries a REAL token, which is what would summon the operator's own details. */
    public function test_no_wizard_field_carries_a_real_autocomplete_token(): void
    {
        $this->operator();

        foreach ([1, 2] as $step) {
            $html = $this->wizardHtml($step);

            preg_match_all('/<input[^>]*data-alta[^>]*>|<input[^>]*id="alta-[^"]*"[^>]*>/', $html, $inputs);

            foreach ($inputs[0] as $input) {
                foreach (['"email"', '"tel"', '"name"', '"given-name"', '"family-name"', '"street-address"', '"bday"'] as $token) {
                    $this->assertStringNotContainsString('autocomplete='.$token, $input, 'a wizard field asks the browser for the operator\'s own data');
                }
            }
        }
    }

    // --- Guided where the data is the person's own ------------------------------------------------

    /** The applicant's own form gets the CORRECT tokens — autofill helps there. */
    public function test_the_applicants_form_guides_autofill(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->location->id]);

        $application = (new IssueApplicationInvite)->handle($manager, $this->location->id, null, 'autofill');
        $html = (string) $this->get(route('socio.application', ['token' => $application->invite_token]))->assertOk()->getContent();

        foreach ([
            'first_name' => 'given-name',
            'last_name' => 'family-name',
            'email' => 'email',
            'phone' => 'tel',
            'date_of_birth' => 'bday',
            'address' => 'street-address',
        ] as $field => $token) {
            preg_match('/<input[^>]*id="'.$field.'"[^>]*>/', $html, $m);
            $this->assertNotEmpty($m, "{$field} is missing from the applicant's form");
            $this->assertStringContainsString('autocomplete="'.$token.'"', $m[0], "{$field} does not tell the browser what it is");
        }
    }

    /**
     * The HANDOVER is the same form at the same route (173/221), so it inherits the guidance by construction
     * — asserted so that a future "handover gets its own template" cannot quietly lose it.
     */
    public function test_the_handover_uses_the_same_guided_form(): void
    {
        $this->operator();

        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');

        $application = MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $html = (string) $this->get(route('socio.application', ['token' => $application->invite_token]))->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="given-name"', $html);
        $this->assertStringContainsString('autocomplete="email"', $html);
    }

    // --- And the paint, for whatever still fills ---------------------------------------------------

    /** The built stylesheet carries the `-webkit-autofill` overrides, light and dark. */
    public function test_the_autofill_paint_is_in_the_stylesheet(): void
    {
        $source = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('-webkit-autofill', $source);
        $this->assertStringContainsString('-webkit-text-fill-color', $source, 'the text colour is not overridden — the UA wins');
        $this->assertStringContainsString('caret-color', $source, 'the caret is not overridden');
        $this->assertStringContainsString('box-shadow: inset 0 0 0 1000px', $source, 'the background is not overridden');
        $this->assertStringContainsString('prefers-color-scheme: dark', substr($source, strpos($source, '-webkit-autofill') ?: 0), 'there is no dark treatment');

        // …and it survived the build, which is what the browser actually loads.
        $built = glob(public_path('build/assets/app-*.css'));
        $this->assertNotEmpty($built, 'no built stylesheet — run npm run build');
        $this->assertStringContainsString('-webkit-autofill', (string) file_get_contents($built[0]), 'the override did not reach the build');
    }
}
