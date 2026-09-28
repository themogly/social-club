<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Settings\SetConsumptionLimits;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Exceptions\LimitExceededException;
use App\Filament\Pages\ManageEnforcement;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\MembershipTiers\Pages\EditMembershipTier;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Period;
use App\Support\Settings;
use App\Support\StockCeiling;
use App\Support\Weight;
use App\ViewModels\Dashboard;
use App\ViewModels\Reports\ConsumptionReport;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 296 (Shane) — consumption limits can be switched off; switching them on asks for the default limit.
 *
 * *"It's confusing for the staff with consumption limits. For now can we have a setting just to disable it?"* A switch,
 * not a deletion. Off: nothing checks a limit and nothing shows one, and everything is still RECORDED exactly as before.
 * On again: the owner is asked for the defaults, with what that will mean for the next shift. The legal stock ceiling,
 * which reads the daily limit, does not move.
 */
class ConsumptionLimitsSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private MembershipTier $tier;

    private Genetic $genetic;

    private TillSession $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        session(['counter.location_id' => $this->sede->id]);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        CounterOperator::set($this->owner);
        $this->till = (new OpenTill)->handle($this->sede, 'POS-1', 10000);

        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 0, 'daily_limit_cg' => null, 'monthly_limit_cg' => null]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id,
            'tier_id' => null, 'price_per_gram_cents' => 100, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id,
            'initial_cg' => 500000, 'remaining_cg' => 500000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function member(array $overrides = []): Member
    {
        $member = Member::factory()->create(array_merge([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => null, 'monthly_limit_cg' => null,
        ], $overrides));
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->sede->id,
            'tier_id' => $this->tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function off(): void
    {
        (new SetConsumptionLimits)->disable($this->owner);
    }

    /** 10 g — far over the default 3,5 g a day and a per-member 5 g a month. */
    private function bigDispensation(Member $member): Dispensation
    {
        return (new CommitDispensation)->handle($member, $this->sede, [['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 1000]], [
            'operator_id' => $this->owner->id, 'till_session_id' => $this->till->id, 'cash_cents' => 1000, 'wallet_cents' => 0,
        ]);
    }

    // --- Limits off ---------------------------------------------------------------------------------------------------

    public function test_off_a_dispensation_over_both_limits_commits_with_no_block_or_override(): void
    {
        $member = $this->member(['monthly_limit_cg' => 500]); // and the default 3,5 g a day
        $this->off();

        $dispensation = $this->bigDispensation($member);

        $this->assertSame(1000, (int) $dispensation->lines()->sum('grams_cg'), 'the grams are recorded exactly as ever');
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('action', 'dispensation.limit.override')->count());
        $this->assertSame('OFF', Settings::enforcement('counter', 'daily_limit'));
        $this->assertSame('OFF', Settings::enforcement('counter', 'monthly_limit'));
    }

    public function test_on_the_same_dispensation_is_still_blocked(): void
    {
        $this->expectException(LimitExceededException::class);
        $this->bigDispensation($this->member());
    }

    public function test_off_the_counter_screens_show_no_allowance_and_no_limit_greyed_preset(): void
    {
        $member = $this->member(['daily_limit_cg' => 100]); // 1 g a day: every preset above it would be greyed
        $this->off();

        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->call('chooseGenetic', $this->genetic->id);
        $html = $pos->html();
        $this->assertStringNotContainsString('data-member-allowance', $html);
        $this->assertStringNotContainsString(__('Restante hoy'), $html);
        $this->assertStringContainsString('data-remaining-after=""', $html);
        $this->assertTrue(collect($pos->instance()->quickEntryPresets())->every(fn (array $p): bool => $p['available']), 'a preset is greyed for a limit that is off');

        foreach ([CheckInScreen::class, MembershipCounter::class] as $screen) {
            $screenHtml = Livewire::test($screen)->call('selectMember', $member->id)->html();
            $this->assertStringNotContainsString('data-member-allowance', $screenHtml, class_basename($screen));
        }
    }

    public function test_off_the_member_area_shows_what_was_taken_with_no_allowance(): void
    {
        $member = $this->member(['daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        $this->bigDispensation($member);
        $this->off();

        $html = (string) $this->actingAs($member, 'member')->get(route('socio.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-consumption-taken', $html);
        $this->assertStringContainsString(Weight::fromCentigrams(1000)->formatted(), $html, 'what was taken is not shown');
        $this->assertStringNotContainsString('data-consumption-allowance', $html, 'the allowance and the percentage still show');
        $this->assertStringNotContainsString(Weight::fromCentigrams(100000)->formatted(), $html);
    }

    public function test_off_the_report_has_no_over_limit_table_and_the_alert_does_not_fire(): void
    {
        $member = $this->member(['daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        $this->bigDispensation($member);
        $member->update(['monthly_limit_cg' => 500]);
        $this->assertSame(1, (new Dashboard($this->org->id, [$this->sede->id], Period::today()))->membersOverLimit());

        $this->off();

        $this->assertSame(0, (new Dashboard($this->org->id, [$this->sede->id], Period::today()))->membersOverLimit());
        $keys = collect((new ConsumptionReport($this->org->id, [$this->sede->id], Period::today()))->tables())->pluck('key');
        $this->assertNotContains('over_limit', $keys);
        $this->assertContains('genetics', $keys, 'the grams per genetic are still reported');
    }

    public function test_off_the_enforcement_page_shows_the_limit_rows_as_off(): void
    {
        $this->off();

        Livewire::test(ManageEnforcement::class)->assertSee(__('Desactivado — ver Ajustes'));
    }

    // --- Nothing else moves -------------------------------------------------------------------------------------------

    public function test_the_stock_ceiling_is_identical_with_limits_on_and_off(): void
    {
        $this->member();
        $on = StockCeiling::forLocation($this->sede);
        $this->off();

        $this->assertSame($on, StockCeiling::forLocation($this->sede));
    }

    public function test_off_then_on_changes_no_stored_member_or_tier_limit(): void
    {
        $member = $this->member(['daily_limit_cg' => 700, 'monthly_limit_cg' => 9000]);
        $this->tier->update(['daily_limit_cg' => 400]);

        $this->off();
        (new SetConsumptionLimits)->enable($this->owner, 350, 10000);

        $this->assertSame([700, 9000], [(int) $member->fresh()->daily_limit_cg, (int) $member->fresh()->monthly_limit_cg]);
        $this->assertSame(400, (int) $this->tier->fresh()->daily_limit_cg);
    }

    public function test_off_stored_limits_stay_editable_with_a_note_beside_them(): void
    {
        Livewire::test(EditMembershipTier::class, ['record' => $this->tier->getRouteKey()])->assertDontSee(__('Límites desactivados en Ajustes'));

        $this->off();

        Livewire::test(EditMembershipTier::class, ['record' => $this->tier->getRouteKey()])
            ->assertSee(__('Límites desactivados en Ajustes'))
            ->assertFormFieldIsEnabled('daily_limit_g');
    }

    // --- Switching on -------------------------------------------------------------------------------------------------

    public function test_switching_on_asks_for_the_defaults_with_the_counts_and_saves_them_together(): void
    {
        $this->member();                                    // no limit of its own → will use the defaults
        $this->member(['monthly_limit_cg' => 20000]);       // its own limit → keeps it
        $heavy = $this->member(['daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        $this->bigDispensation($heavy);                     // 10 g this month…
        $heavy->update(['daily_limit_cg' => null, 'monthly_limit_cg' => null]); // …and now on the defaults
        $this->off();
        Settings::set('daily_limit_cg', 350, SettingType::CG);
        Settings::set('monthly_limit_cg', 10000, SettingType::CG);

        $page = Livewire::test(ManageSettings::class)
            ->set('data.consumption_limits_enabled', true)
            ->assertActionMounted('enableLimits')
            ->assertSet('data.consumption_limits_enabled', false); // nothing saved yet
        $page->assertMountedActionModalSee([
            trans_choice(':count socio sin límite propio usará estos valores.|:count socios sin límite propio usarán estos valores.', 2, ['count' => 2]),
        ]);
        $this->assertFalse(Settings::limitsEnabled());

        // A 5 g month puts the 10 g member over it — counted with ResolveMemberLimits, the counter's own resolver.
        $page->fillForm(['daily_limit_g' => '3.5', 'monthly_limit_g' => '5'])->callMountedAction()->assertHasNoFormErrors();

        $this->assertTrue(Settings::limitsEnabled());
        $this->assertSame([350, 500], [(int) Settings::get('daily_limit_cg'), (int) Settings::get('monthly_limit_cg')]);
        $audits = AuditLog::query()->withoutGlobalScopes()->where('action', 'settings.consumption_limits.enabled')->get();
        $this->assertCount(1, $audits);
        $this->assertSame(['daily_limit_cg' => 350, 'monthly_limit_cg' => 500], array_intersect_key((array) $audits->sole()->after, ['daily_limit_cg' => 1, 'monthly_limit_cg' => 1]));
    }

    public function test_the_modal_counts_members_already_over_the_proposed_month(): void
    {
        $heavy = $this->member(['daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        $this->bigDispensation($heavy);
        $heavy->update(['daily_limit_cg' => null, 'monthly_limit_cg' => null]); // now on the defaults
        $this->off();

        $impact = SetConsumptionLimits::impact(350, 500);

        $this->assertSame(['without_own' => 1, 'over_monthly' => 1], $impact);
        $this->assertSame(['without_own' => 1, 'over_monthly' => 0], SetConsumptionLimits::impact(350, 1000), 'exactly at the limit is not over it');
    }

    public function test_cancel_leaves_limits_off(): void
    {
        $this->off();

        Livewire::test(ManageSettings::class)
            ->set('data.consumption_limits_enabled', true)
            ->assertActionMounted('enableLimits')
            ->call('unmountAction')
            ->assertSet('data.consumption_limits_enabled', false);

        $this->assertFalse(Settings::limitsEnabled());
    }

    public function test_switching_off_asks_first_and_is_audited(): void
    {
        Livewire::test(ManageSettings::class)
            ->set('data.consumption_limits_enabled', false)
            ->assertActionMounted('disableLimits')
            ->callMountedAction();

        $this->assertFalse(Settings::limitsEnabled());
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'settings.consumption_limits.disabled')->count());
    }

    public function test_only_the_owner_can_switch_it(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);

        $this->expectException(AuthorizationException::class);
        (new SetConsumptionLimits)->disable($manager);
    }
}
