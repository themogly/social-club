<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Livewire\Counter\TillSession;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 194 — writes every counter screen that identifies a socio, WITH the lookup's results on screen, to
 * storage/app/lookup-*.html for the measurement pass (`node tests/Browser/measure-one-lookup.mjs`).
 *
 * The results only exist after an interaction (Enter resolves a token first, then searches), so a plain GET
 * cannot reach this state — the components are driven through Livewire and their HTML wrapped in the REAL
 * counter layout, the same way CartColumnHarnessTest reaches a resolved POS.
 *
 * Playwright is not a CI dependency (see the README), so this doubles as the CI structural check: exactly one
 * lookup input per screen, results rendered beneath it, and every row a 44px target. The pixel proof — is the
 * input, and the first row, above the fold at both tablet orientations — is the .mjs script.
 */
class OneLookupHarnessTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'capacity' => 40]);
    }

    private function operator(): User
    {
        $user = User::factory()->create(['name' => 'Marta Ruiz']);
        $user->assignRole(Role::OWNER->value); // one operator for all five screens: pos.use + checkin.manage + fees
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        return $user;
    }

    /** Several socios sharing a surname, so the search returns a LIST rather than a single row. */
    private function members(): Member
    {
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'General']);
        $first = null;

        foreach ([['Lucía', 'García'], ['Marcos', 'García'], ['Ana', 'Garcés'], ['Pau', 'Garrido']] as [$given, $family]) {
            $member = Member::factory()->create([
                'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
                'first_name' => $given, 'last_name' => $family,
                'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
                'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
            ]);
            Membership::factory()->create([
                'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
                'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
            ]);
            $first ??= $member;
        }

        return $first;
    }

    private function sellableGenetic(string $name): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);
    }

    /**
     * Wrap a component's rendered HTML in the REAL counter layout with the BUILT css inlined.
     *
     * `fullHeight` matters and is per-screen: the two selling screens declare it on their own #[Layout], the
     * door, the till and Socios do not. Passing the wrong one photographs a shell nobody sees — the fidelity
     * lesson from prompt 176, kept here rather than re-learned.
     */
    private function write(string $name, string $componentHtml, string $title, bool $fullHeight): void
    {
        $html = view('components.layouts.counter', [
            'slot' => new HtmlString($componentHtml),
            'title' => $title,
            'fullHeight' => $fullHeight,
        ])->render();

        // ONLY app-*.css — theme-*.css is the Filament PANEL theme and never loads on a counter page.
        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }

        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        file_put_contents(storage_path('app/lookup-'.$name.'.html'), $html);
    }

    public function test_it_writes_every_lookup_surface_with_its_results_showing(): void
    {
        // Artifacts first, assertions after — the same convention as the other harnesses, so this file can be
        // run against an older commit to capture a "before".
        $this->operator();
        $member = $this->members();
        $this->sellableGenetic('Amnesia Haze');
        Settings::set('bar_attach_socio_enabled', true, SettingType::BOOL, $this->location->id);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $search = fn (string $component) => Livewire::test($component)->set('lookup', 'Gar')->call('submitLookup');

        // 1) The door — the screen whose entire purpose is identifying somebody.
        $checkin = $search(CheckInScreen::class)->html();
        $this->write('checkin', $checkin, 'Recepción', fullHeight: false);

        // 2) The dispensary's member BLOCKING state: no socio held, so the lookup is the whole screen.
        $blocker = $search(DispensaryPos::class)->html();
        $this->write('dispensary-blocker', $blocker, 'Dispensario', fullHeight: true);

        // 3) The dispensary RESOLVED: a socio is held and the lookup has moved into the scrolling selection
        //    pane, above the genetics. This is the hard fold case — the one 176 rebuilt the screen over.
        $pane = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->set('lookup', 'Gar')->call('submitLookup')
            ->html();
        $this->write('dispensary-pane', $pane, 'Dispensario', fullHeight: true);

        // 4) Socios, 5) the caja, 6) the bar — the three that had no scan affordance at all before 194.
        $socios = $search(MembershipCounter::class)->html();
        $this->write('socios', $socios, 'Socios', fullHeight: false);

        $till = $search(TillSession::class)->html();
        $this->write('till', $till, 'Caja', fullHeight: false);

        $bar = $search(BarPos::class)->html();
        $this->write('bar', $bar, 'Barra', fullHeight: true);

        // --- now the assertions ---

        foreach (['checkin' => $checkin, 'dispensary-blocker' => $blocker, 'dispensary-pane' => $pane,
            'socios' => $socios, 'till' => $till, 'bar' => $bar] as $name => $html) {
            $this->assertSame(1, substr_count($html, 'id="member-lookup"'), $name.' renders ONE lookup field');
            $this->assertStringContainsString('data-member-lookup-results', $html, $name.' renders its results in place');

            preg_match_all('/<button[^>]*data-member-lookup-result[^>]*>/s', $html, $rows);
            $this->assertGreaterThanOrEqual(3, count($rows[0]), $name.' surfaced the matching socios');

            foreach ($rows[0] as $row) {
                $this->assertStringContainsString('min-h-[2.75rem]', $row, $name.' rows clear the 44px floor');
            }
        }
    }
}
