<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 182 — opening the till: one action, and stop making them type the same number every morning.
 *
 * Split out of prompt 175, which bundled it with standardising the blocking pattern; they are different
 * jobs with different decisions and bundling them is why 175 could not land in one go.
 *
 * The default-float mechanism is a `Settings` value (the SumUp / Toast shape), NOT a carry-forward from the
 * previous close (Shopify / Dutchie) — see DECISIONS. What is asserted here is that it pre-fills, that the
 * operator can always override it, that the overridden value is what is stored, and that the first-ever
 * open with no default at all still works and says why the box is empty.
 *
 * What opening a till DOES is untouched: `OpenTill`, the float amount, the audit trail and the whole
 * close/count/variance flow are unchanged. This is the screen in front of them.
 */
class TillOpenScreenTest extends TestCase
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
        CounterOperator::set($user);

        return $user;
    }

    /** The integer actually in the column — never the MoneyCast value object. */
    private function storedFloatCents(): ?int
    {
        $value = DB::table('till_sessions')->value('float_cents');

        return $value === null ? null : (int) $value;
    }

    private function setDefaultFloat(int $cents): void
    {
        Settings::set('till_default_float_cents', $cents, SettingType::CENTS, $this->location->id);
    }

    // --- one screen, one action -----------------------------------------------------------------------

    public function test_the_open_screen_is_the_screen_not_a_card_among_cards(): void
    {
        $this->operator();

        $html = Livewire::test(TillSession::class)->html();

        $this->assertStringContainsString('data-till-open-screen', $html);
        $this->assertStringContainsString('data-till-open-action', $html);
        // The float is on the SAME screen as the action — no wizard step, as every vendor does it.
        $this->assertStringContainsString('data-till-float', $html);
    }

    public function test_there_is_exactly_one_open_action(): void
    {
        $this->operator();

        $html = Livewire::test(TillSession::class)->html();

        $this->assertSame(1, substr_count($html, 'data-till-open-action'));
    }

    // --- the default pre-fills, and can be overridden --------------------------------------------------

    public function test_the_default_float_prefills_the_box(): void
    {
        $this->operator();
        $this->setDefaultFloat(15000); // €150,00

        $component = Livewire::test(TillSession::class);

        $this->assertSame('150,00', $component->get('floatInput'));
        $this->assertStringContainsString('data-float-default', $component->html());
    }

    public function test_opening_with_the_default_untouched_stores_the_default(): void
    {
        $this->operator();
        $this->setDefaultFloat(15000);

        Livewire::test(TillSession::class)->call('open');

        // The REAL stored amount, read raw — MoneyCast hands back a value object, and what matters here is
        // the integer in the column.
        $this->assertSame(15000, $this->storedFloatCents());
    }

    public function test_the_operator_can_override_it_and_the_override_is_what_is_stored(): void
    {
        $this->operator();
        $this->setDefaultFloat(15000);

        // A pre-filled figure they cannot change is worse than typing.
        Livewire::test(TillSession::class)->set('floatInput', '80,50')->call('open');

        $this->assertSame(8050, $this->storedFloatCents());
    }

    public function test_a_decimal_entry_rounds_the_way_the_rest_of_the_product_rounds(): void
    {
        $this->operator();

        Livewire::test(TillSession::class)->set('floatInput', '12,345')->call('open');

        // round_half_up at the euro edge — 12,345 → 1235 cents, never a float in the column.
        $stored = $this->storedFloatCents();
        $this->assertIsInt($stored);
        $this->assertSame(1235, $stored);
    }

    // --- the first-ever open ---------------------------------------------------------------------------

    public function test_the_first_ever_open_has_no_default_and_says_so(): void
    {
        $this->operator();

        $component = Livewire::test(TillSession::class);

        // No default, no previous session: an EMPTY required field with no explanation is the failure mode.
        $this->assertSame('', $component->get('floatInput'));
        $this->assertNull($component->instance()->defaultFloatCents());
        $this->assertStringContainsString('data-float-no-default', $component->html());
        $this->assertStringNotContainsString('data-float-default"', $component->html());
    }

    public function test_the_first_ever_open_still_opens(): void
    {
        $this->operator();

        Livewire::test(TillSession::class)->set('floatInput', '50,00')->call('open');

        $this->assertSame(5000, $this->storedFloatCents());
    }

    public function test_a_zero_default_reads_as_no_default_rather_than_a_zero_float(): void
    {
        $this->operator();
        $this->setDefaultFloat(0);

        $component = Livewire::test(TillSession::class);

        // 0 means "not configured", not "open with an empty drawer" — otherwise every sede that never set
        // one would silently propose zero as though somebody had chosen it.
        $this->assertNull($component->instance()->defaultFloatCents());
        $this->assertSame('', $component->get('floatInput'));
    }

    public function test_the_prefill_never_overwrites_something_already_typed(): void
    {
        $this->operator();
        $this->setDefaultFloat(15000);

        $component = Livewire::test(TillSession::class)->set('floatInput', '20,00');
        $component->call('$refresh');

        $this->assertSame('20,00', $component->get('floatInput'));
    }

    // --- the multi-till case survives -------------------------------------------------------------------

    public function test_with_several_tills_the_operator_still_chooses_and_nothing_is_guessed(): void
    {
        $this->operator();
        Settings::set('multiple_tills_enabled', true, SettingType::BOOL, $this->location->id);
        Settings::set('till_terminals', ['POS-1', 'POS-2'], SettingType::JSON, $this->location->id);

        $component = Livewire::test(TillSession::class);

        $this->assertTrue($component->instance()->multipleTills());
        $this->assertSame('', $component->get('terminal'), 'a terminal was guessed');

        // Opening without picking one is refused, not defaulted.
        $component->set('floatInput', '50,00')->call('open');
        $this->assertSame(0, TillSessionModel::query()->withoutGlobalScopes()->count());
    }

    public function test_the_default_float_still_prefills_on_a_multi_till_sede(): void
    {
        $this->operator();
        Settings::set('multiple_tills_enabled', true, SettingType::BOOL, $this->location->id);
        $this->setDefaultFloat(20000);

        $this->assertSame('200,00', Livewire::test(TillSession::class)->get('floatInput'));
    }

    // --- nothing else moved ------------------------------------------------------------------------------

    public function test_a_session_opened_from_this_screen_is_identical_to_one_opened_by_the_action(): void
    {
        $this->operator();
        $this->setDefaultFloat(10000);

        Livewire::test(TillSession::class)->call('open');
        $fromScreen = TillSessionModel::query()->withoutGlobalScopes()->sole();
        $terminal = $fromScreen->terminal;

        // The screen is a caller of OpenTill, not a second writer — assert against the real thing.
        $fromScreen->forceDelete();
        $direct = (new OpenTill)->handle($this->location, $terminal, 10000);

        $this->assertSame($fromScreen->getRawOriginal('float_cents'), $direct->getRawOriginal('float_cents'));
        $this->assertSame($fromScreen->terminal, $direct->terminal);
        $this->assertSame($fromScreen->status, $direct->status);
        $this->assertSame($fromScreen->location_id, $direct->location_id);
    }

    public function test_an_open_session_replaces_the_open_screen_rather_than_stacking_with_it(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = Livewire::test(TillSession::class)->html();

        $this->assertStringNotContainsString('data-till-open-screen', $html);
    }

    public function test_the_setting_is_read_through_the_accessor_and_degrades_to_no_default(): void
    {
        $this->operator();

        // A stale or missing value must degrade gracefully, never throw on a counter screen at 9am.
        Settings::set('till_default_float_cents', 'not-a-number', SettingType::STRING, $this->location->id);

        $this->assertNull(Livewire::test(TillSession::class)->instance()->defaultFloatCents());
    }
}
