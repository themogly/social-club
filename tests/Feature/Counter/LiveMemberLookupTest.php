<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\IssueMemberToken;
use App\Actions\Till\OpenTill;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 204 — the one member lookup gives results as you type.
 *
 * Prompt 194 consolidated seven inputs into one, which was right, and shipped it as a form you had to
 * SUBMIT. Its reasoning: one box cannot search per keystroke, because a token has to be resolved whole and a
 * half-typed name would reach prompt 58's failed-scan throttle. **The first half is true and the second does
 * not follow.** The two lookups are separable — only `submitLookup()` resolves tokens, and only it can reach
 * the throttle — so the name search can run on every keystroke for nothing.
 *
 * The evidence that it needed fixing was in 194's own copy: it had to put *"pulsa Enter"* in the placeholder,
 * and argued that the instruction was load-bearing. **A control that has to teach its own keystroke is the
 * defect.** Three of the five screens it replaced already searched live; an operator with a socio standing in
 * front of them typed, and waited.
 *
 * It is also now a real **combobox**. Before this the results were an unowned `<ul>` that simply appeared in
 * the DOM next to an input claiming to be a plain textbox: no `role`, no `aria-expanded`, no
 * `aria-activedescendant`, and no way to reach a row from the keyboard at all. A screen-reader user got no
 * announcement that anything had happened.
 */
class LiveMemberLookupTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 20]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    private function member(string $first = 'Lucía', string $last = 'García', string $no = 'M-00099'): Member
    {
        return Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'first_name' => $first, 'last_name' => $last, 'member_no' => $no,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
        ]);
    }

    /**
     * Put every screen in the state where its lookup is actually ON SCREEN.
     *
     * Without this the two POS screens render the till blocker and the bar hides its socio card behind a
     * per-sede flag — so a sweep would report "no violations" over screens it never audited, which is the
     * axe-sweep defect from the accessibility branch, one directory over.
     */
    private function everyLookupReachable(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        Settings::set('bar_attach_socio_enabled', true, SettingType::BOOL, $this->location->id);
    }

    // --- Live ---------------------------------------------------------------------

    /**
     * Typing alone produces results. No `submitLookup`, no Enter.
     *
     * `->set()` is exactly what `wire:model.live` does on the wire, so this is the real keystroke path.
     */
    public function test_typing_produces_results_without_pressing_enter(): void
    {
        $this->operator();
        $this->member();

        Livewire::test(CheckInScreen::class)
            ->set('lookup', 'Garc')
            ->assertSet('lookupSearched', false, 'nothing was submitted — this is typing, not Enter')
            ->assertSee('García')
            ->assertSee('M-00099');
    }

    /** A member number searches live too — it is the same field and the same query. */
    public function test_a_member_number_searches_live(): void
    {
        $this->operator();
        $this->member();

        Livewire::test(CheckInScreen::class)
            ->set('lookup', 'M-000')
            ->assertSee('García');
    }

    /** One character is not a search. Two is the floor 194 set, and it is kept. */
    public function test_a_single_character_searches_nothing(): void
    {
        $this->operator();
        $this->member();

        $html = Livewire::test(CheckInScreen::class)->set('lookup', 'G')->html();

        $this->assertStringNotContainsString('data-member-lookup-results', $html);
        $this->assertStringNotContainsString('M-00099', $html, 'no row rendered');
        // Not asserted on the name: "García" is in the placeholder example, and a test that reads its own
        // copy back as evidence proves nothing.
        $this->assertStringContainsString('aria-expanded="false"', $html);
    }

    /** A miss says so, live — an empty box would read as "still thinking". */
    public function test_a_live_miss_says_so(): void
    {
        $this->operator();
        $this->member();

        Livewire::test(CheckInScreen::class)
            ->set('lookup', 'Zzzz')
            ->assertSee(__('Sin resultados.'));
    }

    /**
     * Typing thirty misses still locks nothing.
     *
     * This is the assertion 194's reasoning was actually about, now made against the LIVE path: live search
     * never calls ResolveMemberByToken, so the failed-scan throttle cannot see it. Without this the branch
     * would have swapped a usability problem for a door that locks itself mid-service.
     */
    public function test_thirty_live_misses_never_touch_the_scan_throttle(): void
    {
        $operator = $this->operator();
        $component = Livewire::test(CheckInScreen::class);

        for ($i = 1; $i <= 30; $i++) {
            $component->set('lookup', 'Nadie'.$i)->assertSet('flashMessage', null);
        }

        $this->assertSame(0, RateLimiter::attempts('qr-scan:'.$operator->id), 'typing is not scanning');
    }

    // --- Scanning still works, and does not flicker -------------------------------

    /**
     * A card token is not searched WHILE it is arriving.
     *
     * A wedge reader types its 48 characters into this box before its trailing Return, so a live search would
     * run a name query on a partial token and paint "Sin resultados." under every single scan.
     */
    public function test_a_scan_shaped_term_shows_nothing_until_enter(): void
    {
        $this->operator();
        $member = $this->member();
        $token = (new IssueMemberToken)->handle($member);

        $html = Livewire::test(CheckInScreen::class)->set('lookup', $token)->html();

        $this->assertStringNotContainsString(__('Sin resultados.'), $html, 'no flicker while the card arrives');
        $this->assertStringNotContainsString('data-member-lookup-results', $html);
    }

    /** …and the trailing Return still identifies the socio, which is the whole point of the box. */
    public function test_the_trailing_return_still_identifies_the_socio(): void
    {
        $this->operator();
        $member = $this->member();

        Livewire::test(CheckInScreen::class)
            ->set('lookup', (new IssueMemberToken)->handle($member))
            ->call('submitLookup')
            ->assertSet('memberId', $member->id);
    }

    /**
     * An UNRECOGNISED card still falls through to the search after Enter.
     *
     * The suppression above is "while typing", not "ever" — 194's fall-through has to survive it, or a
     * mis-scanned card lands on a blank box instead of "Sin resultados."
     */
    public function test_an_unrecognised_card_still_falls_through_after_enter(): void
    {
        $this->operator();

        Livewire::test(CheckInScreen::class)
            ->set('lookup', str_repeat('a', 48))
            ->call('submitLookup')
            ->assertSet('lookupSearched', true)
            ->assertSee(__('Sin resultados.'));
    }

    // --- It is a combobox, not a list that appeared -------------------------------

    /** The ARIA a list-under-a-textbox is required to have, on every screen that hosts the field. */
    public function test_the_field_is_a_combobox_that_owns_its_listbox(): void
    {
        $this->operator();
        $this->member();

        $this->everyLookupReachable();

        foreach ([CheckInScreen::class, DispensaryPos::class, BarPos::class, MembershipCounter::class] as $screen) {
            $html = Livewire::test($screen)->set('lookup', 'Garc')->html();
            $name = class_basename($screen);

            $this->assertStringContainsString('id="member-lookup"', $html, $name.' has no lookup — nothing was audited');

            $this->assertStringContainsString('role="combobox"', $html, $name);
            $this->assertStringContainsString('aria-controls="member-lookup-results"', $html, $name);
            $this->assertStringContainsString('aria-autocomplete="list"', $html, $name);
            $this->assertStringContainsString('aria-expanded="true"', $html, $name.' must announce the open list');
            $this->assertStringContainsString('id="member-lookup-results"', $html, $name);
            $this->assertStringContainsString('role="listbox"', $html, $name);
            $this->assertStringContainsString('role="option"', $html, $name.' rows must be options');
            $this->assertStringContainsString('id="member-lookup-option-0"', $html, $name.' options need ids to be active-descended');
        }
    }

    /** Closed means closed: `aria-expanded` is false with nothing to show, and the listbox still exists. */
    public function test_the_combobox_reports_itself_closed_when_there_is_nothing_to_show(): void
    {
        $this->operator();

        $html = Livewire::test(CheckInScreen::class)->html();

        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('id="member-lookup-results"', $html, 'aria-controls must never dangle');
        $this->assertStringNotContainsString('data-member-lookup-results', $html);
    }

    /** The keyboard behaviour is wired on the field itself — measured for real in the browser prover. */
    public function test_the_field_carries_the_keyboard_bindings(): void
    {
        $this->operator();

        $html = Livewire::test(CheckInScreen::class)->html();

        foreach (['keydown.arrow-down', 'keydown.arrow-up', 'keydown.enter', 'keydown.escape'] as $binding) {
            $this->assertStringContainsString($binding, $html, $binding.' must be bound on the lookup');
        }
    }

    // --- And the placeholder stopped teaching a keystroke -------------------------

    /**
     * No screen tells the operator to press Enter any more.
     *
     * A view-tree grep rather than a copy assertion: the instruction could come back in any of the five
     * screens or either placeholder branch, and the point is that NOWHERE needs it now.
     */
    public function test_no_lookup_copy_instructs_the_operator_to_press_a_key(): void
    {
        $this->operator();

        $this->everyLookupReachable();

        foreach ([CheckInScreen::class, DispensaryPos::class, BarPos::class, MembershipCounter::class] as $screen) {
            $html = Livewire::test($screen)->html();
            $this->assertStringContainsString('id="member-lookup"', $html, class_basename($screen).' has no lookup');
            $this->assertStringNotContainsString('pulsa Enter', $html, class_basename($screen));
            $this->assertStringNotContainsString('press Enter', $html, class_basename($screen));
        }
    }
}
