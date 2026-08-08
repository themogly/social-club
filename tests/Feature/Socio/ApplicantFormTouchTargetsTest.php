<?php

namespace Tests\Feature\Socio;

use App\Actions\Members\IssueApplicationInvite;
use App\Enums\Role;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 217 — the product's one phone-first page carried its smallest touch targets.
 *
 * Measured on `d43c81c`, assets rebuilt, in a real browser at **390×844 with touch** — the size that matters,
 * because `socio/application.blade.php` is the one genuinely phone-first surface: an applicant on their own
 * device, or holding the club's tablet in portrait. 22 interactive elements, **9 under the 44px floor**:
 *
 *   31×24  / 32×24   the two locale buttons
 *   316×36 / 316×36  the photo and document-scan file inputs
 *   **16×20          `is_therapeutic` — a bare checkbox with no wrapping label target**
 *   290×40 / 290×40  the two consent labels (4px short, but at least the label WAS the target)
 *
 * `is_therapeutic` is the sharp one: a therapeutic declaration that is genuinely hard to tick, and — being a
 * health-adjacent fact — one where mis-ticking in either direction matters more than most fields. The two
 * consent rows beside it already had the label treatment; it did not. One construction serves all three now.
 *
 * 215 reported this and correctly left it out of scope. This is the branch it was left for.
 */
class ApplicantFormTouchTargetsTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->location = Location::factory()->create(['organisation_id' => $org->id]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole(Role::MANAGER->value);
        $this->manager->locations()->sync([$this->location->id]);
    }

    private function application(): MemberApplication
    {
        return (new IssueApplicationInvite)->handle($this->manager, $this->location->id, null, 'touch-targets');
    }

    private function form(): string
    {
        return (string) $this->get(route('socio.application', ['token' => $this->application()->invite_token]))
            ->assertOk()->getContent();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /** Tailwind classes that put an element at or above the 44px floor. */
    private function clearsTheFloor(?DOMElement $element): bool
    {
        if ($element === null) {
            return false;
        }

        $class = $element->getAttribute('class');

        // `min-h-11` is 2.75rem = 44px; the bracket form is the same figure written out.
        return str_contains($class, 'min-h-11')
            || str_contains($class, 'min-h-[2.75rem]')
            || str_contains($class, 'h-11')
            || str_contains($class, 'h-12');
    }

    // --- The structural guard, in `composer check` ----------------------------------------------

    /**
     * **Every checkbox on the applicant's form sits in a tap target that clears the floor.**
     *
     * Written so it fails against `main`, which it does on `is_therapeutic`: its label carried `p-3` and no
     * height floor, so the input itself — 16×20 — was all a finger had to aim at.
     *
     * Asserted on the CONSTRUCTION rather than by listing the three we know about, so a fourth checkbox
     * cannot arrive without one.
     */
    public function test_every_checkbox_is_inside_a_tap_target_that_clears_the_floor(): void
    {
        $xpath = $this->xpath($this->form());

        $checkboxes = $xpath->query('//input[@type="checkbox"]');
        $this->assertGreaterThanOrEqual(3, $checkboxes->length, 'the form rendered too few checkboxes to be a real check');

        $offenders = [];

        foreach ($checkboxes as $checkbox) {
            if (! $checkbox instanceof DOMElement) {
                continue;
            }

            $label = $xpath->query('ancestor::label', $checkbox)->item(0);

            if (! $this->clearsTheFloor($label instanceof DOMElement ? $label : null)) {
                $offenders[] = $checkbox->getAttribute('name') ?: '(unnamed)';
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These checkboxes are not inside a 44px tap target: '.implode(', ', $offenders).'.',
            '',
            'This is the product\'s one phone-first page — an applicant on their own device, or holding the',
            'club\'s tablet in portrait. `is_therapeutic` shipped as a bare 16×20 target (prompt 217).',
            'Render it through socio/partials/consent-check, which is the one construction all three use.',
        ]));
    }

    /** The two file inputs carry the floor on the INPUT, so the whole row is tappable. */
    public function test_the_file_inputs_clear_the_floor(): void
    {
        $xpath = $this->xpath($this->form());

        foreach (['photo', 'document_scan'] as $name) {
            $input = $xpath->query('//input[@type="file"][@name="'.$name.'"]')->item(0);

            $this->assertInstanceOf(DOMElement::class, $input, "{$name} is missing");
            $this->assertTrue(
                $this->clearsTheFloor($input),
                "{$name} is under the touch floor — pad the input, not the file: pseudo-button alone",
            );
        }
    }

    /** The locale toggle: discreet, and 44×44. */
    public function test_the_locale_buttons_clear_the_floor(): void
    {
        $xpath = $this->xpath($this->form());

        $buttons = $xpath->query('//*[@data-locale-switcher]//button');
        $this->assertGreaterThan(0, $buttons->length, 'the locale switcher did not render');

        foreach ($buttons as $button) {
            $this->assertTrue(
                $this->clearsTheFloor($button instanceof DOMElement ? $button : null),
                'a locale button is under the touch floor',
            );
        }
    }

    /** One construction, not three copies — the partial exists and the form renders all three through it. */
    public function test_all_three_checkboxes_share_one_construction(): void
    {
        $this->assertFileExists(resource_path('views/socio/partials/consent-check.blade.php'));

        $blade = (string) file_get_contents(resource_path('views/socio/application.blade.php'));

        foreach (['is_therapeutic', 'consent_data', 'consent_statutes'] as $field) {
            $this->assertMatchesRegularExpression(
                "/@include\('socio\.partials\.consent-check',[^)]*'{$field}'/s",
                $blade,
                "{$field} still has its own hand-written checkbox markup",
            );
        }
    }

    // --- Nothing about the form changed ----------------------------------------------------------

    /** Submission still works end to end — every box ticked through its label, from the phone viewport. */
    public function test_the_form_still_submits(): void
    {
        $application = $this->application();

        $this->travelTo(now()->subSeconds(ApplicationSpamGuard::MIN_SECONDS + 2));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        $this->post(route('socio.application.store', ['token' => $application->invite_token]), [
            'first_name' => 'Lucía',
            'last_name' => 'García',
            'email' => 'lucia@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI',
            'document_number' => '12345678Z',
            'is_therapeutic' => '1',
            'consent_data' => '1',
            'consent_statutes' => '1',
            'signature' => 'data:image/png;base64,'.base64_encode('sig'),
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $token,
        ]);

        $payload = $application->fresh()->payload;

        $this->assertNotNull($application->fresh()->submitted_at, 'the form no longer submits');
        $this->assertSame('Lucía', $payload['first_name']);
        $this->assertTrue($payload['is_therapeutic'], 'the therapeutic tick did not survive the new construction');
        $this->assertSame(['membership', 'data_processing'], $payload['consents']);
    }

    /** 173: this page renders for an applicant holding the tablet, and gains no chrome and no way out. */
    public function test_the_page_still_carries_no_counter_chrome(): void
    {
        $html = $this->form();

        foreach ([
            'data-counter-topbar', 'data-counter-home-link', 'data-counter-lock',
            'data-counter-admin-link', 'data-counter-logout', 'data-counter-panic',
        ] as $hook) {
            $this->assertStringNotContainsString($hook, $html, "the applicant's form gained {$hook}");
        }
    }
}
