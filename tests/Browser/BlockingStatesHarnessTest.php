<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 175 — writes the REAL, authed dispensary in each of its blocking states, with the built CSS
 * inlined, to storage/app/blocker-*.html for the Playwright screenshot pass
 * (`node tests/Browser/measure-blocking-states.mjs`).
 *
 * Playwright is not a CI dependency (see the README), so this doubles as the CI structural check: exactly
 * one blocking state per cold-start state, and the till action is the brand button rather than the
 * destructive one. The pixel/appearance proof is the .mjs script.
 */
class BlockingStatesHarnessTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
    }

    private function operator(bool $withLocation = true): User
    {
        $user = User::factory()->create(['name' => 'Marta Ruiz']);
        // MANAGER, not OWNER: an owner has org-wide access, so with one sede in the org the resolver
        // adopts it and the no-sede state is unreachable.
        $user->assignRole(Role::MANAGER->value);

        if ($withLocation) {
            $user->locations()->sync([$this->location->id]);
        }

        $this->actingAs($user);

        if ($withLocation) {
            app(ActiveScope::class)->setLocation($this->location->id);
        }

        CounterOperator::set($user);

        return $user;
    }

    /** A priced, stocked genetic so the resolved screen has something real on it. */
    private function sellableGenetic(string $name): Genetic
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

        return $genetic;
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'first_name' => 'Lucía', 'last_name' => 'García',
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'General']);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function write(string $name, string $html): void
    {
        // ONLY app-*.css. The counter layout loads `resources/css/app.css` and nothing else; `theme-*.css`
        // is the Filament PANEL theme and is never on a counter page. Globbing `*.css` appends it AFTER
        // app.css and corrupts the cascade — found in prompt 176, where it silently defeated `md:flex-row`
        // and made a two-pane layout measure as a stack.
        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }

        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        file_put_contents(storage_path('app/blocker-'.$name.'.html'), $html);
    }

    public function test_it_writes_each_dispensary_blocking_state_for_the_screenshot_pass(): void
    {
        // Render and WRITE all three first, then assert. The artifacts are the point of this file, and
        // writing them before the assertions is what lets the same harness be run against an older commit
        // (where the assertions cannot hold) to capture the "before" side of the comparison.

        // 1) SEDE — signed in, PIN-identified, but no sede assigned at all.
        $this->operator(withLocation: false);
        $sede = $this->get(route('counter.pos'))->getContent();
        $this->write('sede', $sede);

        // 2) TILL — a sede and an operator, but no till open at this terminal. This is the COLD START: the
        // state in which main drew three things at once.
        $this->operator();
        $this->sellableGenetic('Amnesia Haze');
        $till = $this->get(route('counter.pos'))->getContent();
        $this->write('till', $till);

        // 3) MEMBER — the till is open; the last link in the chain, carrying its own lookup.
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $member = $this->get(route('counter.pos'))->getContent();
        $this->write('member', $member);

        // --- now the assertions ---

        $this->assertSame(1, substr_count($sede, 'data-counter-blocker'));
        $this->assertStringContainsString('data-blocker="sede"', $sede);

        $this->assertSame(1, substr_count($till, 'data-counter-blocker'));
        $this->assertStringContainsString('data-blocker="till"', $till);
        // Colour has one meaning: the action is navigation, so it is the brand button, not bg-error.
        $this->assertMatchesRegularExpression('/data-blocker-action\s+class="[^"]*bg-brand/', $till);

        $this->assertSame(1, substr_count($member, 'data-counter-blocker'));
        $this->assertStringContainsString('data-blocker="member"', $member);
        $this->assertStringContainsString('id="member-search"', $member); // the fix is inside the blocker

        // The resolved screen is deliberately NOT written here: DispensaryPos::mount() takes no parameters
        // and reads no member from the request, so a plain GET cannot reach it — only a Livewire interaction
        // can. Its layout is covered by CounterStaffDayTest; this harness is for the blocking states.
    }
}
