<?php

namespace Tests\Feature\Till;

use App\Actions\Bar\CommitOrder;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Till\CloseTill;
use App\Actions\Till\HandOverTill;
use App\Actions\Till\OpenTill;
use App\Actions\Till\RecordCashMovement;
use App\Enums\BatchStatus;
use App\Enums\CashMovementType;
use App\Enums\CashPot;
use App\Enums\FeePaymentMethod;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TillSession as TillScreen;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Period;
use App\Support\Settings;
use App\Support\TillSummary;
use App\Support\ZReport;
use App\ViewModels\Dashboard;
use App\ViewModels\Reports\TillReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 349 — Aaron: "Membership and bar are separate tills." Ben: "When cashing up, they keep the money in separate
 * pots. It's only the dispensary that actually gets counted up every night… Make sure when doing the float it just looks
 * at the dispensary."
 */
class CashPotsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 10000, 'monthly_limit_cg' => 100000]);
    }

    private function pots(bool $on = true): void
    {
        Settings::set('separate_cash_pots', $on, SettingType::BOOL, (string) $this->sede->id);
    }

    /** A €20 cash dispensation, a €5 bar sale, a €10 fee — each through its own writer, on this session. */
    private function trade(TillSession $session): Membership
    {
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 1000]);
        $membership = (new EnrolMembership)->handle($this->member, $this->sede, $tier, ['actor' => $this->owner]);

        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 5000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
        (new CommitDispensation)->handle($this->member, $this->sede, [['genetic_id' => $genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 200]],
            ['operator_id' => $this->owner->id, 'till_session_id' => $session->id, 'cash_cents' => 2000]);

        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'price_cents' => 500, 'stock' => 10]);
        (new CommitOrder)->handle($this->sede, [['article_id' => $article->id, 'qty' => 1]], ['operator_id' => $this->owner->id, 'till_session_id' => $session->id, 'cash_cents' => 500]);

        (new RecordFeePayment)->handle($membership->fresh(), 1000, FeePaymentMethod::CASH, ['till_session_id' => $session->id, 'operator_id' => $this->owner->id]);

        return $membership;
    }

    // --- 1. Which pot each source feeds ----------------------------------------------------------------------------------------

    public function test_each_source_feeds_its_own_pot(): void
    {
        $this->pots();
        $session = (new OpenTill)->handle($this->sede, 'Caja 1', 0, ['operator_id' => $this->owner->id]);
        $this->trade($session);

        $b = TillSummary::breakdown($session->fresh());
        $this->assertTrue($b['separate_pots']);
        $this->assertSame(2000, $b['pots']['DISPENSARY']['expected']);
        $this->assertSame(500, $b['pots']['BAR']['expected']);
        $this->assertSame(1000, $b['pots']['FEES']['expected']);
    }

    public function test_a_combined_visit_splits_by_part_and_says_what_goes_in_the_bar_pot(): void
    {
        $this->pots();
        Settings::set('after_recording', 'stay', SettingType::STRING, (string) $this->sede->id);
        $session = (new OpenTill)->handle($this->sede, 'Caja 1', 0, ['operator_id' => $this->owner->id]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 0]);
        (new EnrolMembership)->handle($this->member, $this->sede, $tier, ['actor' => $this->owner, 'fee_cents' => 0]);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 5000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
        $drink = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'price_cents' => 340, 'stock' => 10]);

        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);
        $pos = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)->call('chooseGenetic', $genetic->id)->set('weightInput', '2')->call('addLine')
            ->call('addBarItem', $drink->id)->set('cashTendered', '50')->call('commitDispensation');

        $pos->assertSee(__('Pon :amount en el bote de la barra.', ['amount' => Money::fromCents(340)->formatted()]));
        $b = TillSummary::breakdown($session->fresh());
        $this->assertSame(2000, $b['pots']['DISPENSARY']['expected']);
        $this->assertSame(340, $b['pots']['BAR']['expected']);
    }

    public function test_the_till_screens_close_asks_per_pot_and_starts_on_each_sedes_count_every_night(): void
    {
        $this->pots();
        Settings::set('count_fees_nightly', true, SettingType::BOOL, (string) $this->sede->id);
        $session = (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        // Bar and fee takings only: no flower sold, so the close goes straight to the count (no re-weigh step first).
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 1000]);
        $membership = (new EnrolMembership)->handle($this->member, $this->sede, $tier, ['actor' => $this->owner]);
        (new RecordFeePayment)->handle($membership->fresh(), 1000, FeePaymentMethod::CASH, ['till_session_id' => $session->id, 'operator_id' => $this->owner->id]);
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);

        $screen = Livewire::test(TillScreen::class)->call('startClose')
            ->assertSet('potCountNow.BAR', false)->assertSet('potCountNow.FEES', true)
            ->assertSeeHtml('data-pot-count="BAR"')->assertSeeHtml('data-pot-count="FEES"')
            ->assertDontSeeHtml('data-till-expected'); // still blind
        $screen->set('countInput', '100')->set('potCountInput.FEES', '10')->call('submitCount')
            ->assertSet('countSubmitted', true)
            ->assertSet('potResults.BAR.counted', null)->assertSet('potResults.FEES.variance', 0);
    }

    // --- 6. The float and the headline are the dispensary's -----------------------------------------------------------------------

    public function test_a_100_float_makes_the_headline_120_not_135_and_shows_bar_and_fees_apart(): void
    {
        $this->pots();
        $session = (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        $this->trade($session);

        $this->assertSame(12000, TillSummary::expectedCents($session->fresh()));

        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);
        $html = Livewire::test(TillScreen::class)->html();
        $this->assertMatchesRegularExpression('/data-till-expected.*?120[.,]00/s', $html);
        $this->assertMatchesRegularExpression('/data-till-pot="BAR".*?5[.,]00/s', $html);
        $this->assertMatchesRegularExpression('/data-till-pot="FEES".*?10[.,]00/s', $html);
    }

    // --- 7. The handover counts the dispensary pot -------------------------------------------------------------------------------

    public function test_a_handover_counting_120_has_no_difference_and_135_is_plus_15(): void
    {
        $this->pots();
        $session = (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        $this->trade($session);
        $next = User::factory()->create();
        $next->assignRole(Role::MANAGER->value);

        (new HandOverTill)->handle($session->fresh(), 12000, $this->owner, $next);
        $closed = $session->shifts()->whereNotNull('closed_at')->latest('closed_at')->first();
        $this->assertSame(0, (int) $closed->getRawOriginal('variance_cents'));

        // A minute later: two handovers in one second tie on closed_at, and ULIDs minted in one millisecond have no
        // guaranteed order, so «the latest shift» was sometimes the first one (a flake seen on 6 Oct 2026).
        $this->travel(1)->minutes();
        (new HandOverTill)->handle($session->fresh(), 13500, $next, $this->owner);
        $second = $session->shifts()->whereNotNull('closed_at')->orderByDesc('closed_at')->orderByDesc('id')->first();
        $this->assertSame(1500, (int) $second->getRawOriginal('variance_cents'));
    }

    // --- 2–3. Closing counts what the club counts; uncounted pots carry ---------------------------------------------------------

    public function test_counting_only_the_dispensary_carries_bar_and_fees_and_a_later_count_is_against_the_accumulated(): void
    {
        $this->pots();
        $first = (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        $this->trade($first);

        $closed = (new CloseTill)->handle($first->fresh(), 12000, $this->owner);
        $this->assertSame(12000, $closed->expected_cents->cents);
        $this->assertSame(0, $closed->variance_cents->cents, 'the dispensary pot alone is compared');
        $this->assertNull($closed->getRawOriginal('bar_counted_cents'));
        $this->assertSame(500, (int) $closed->getRawOriginal('bar_expected_cents'));
        $this->assertSame(1000, (int) $closed->getRawOriginal('fees_expected_cents'));

        $second = (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        $this->assertSame(500, $second->bar_opening_cents->cents, 'the uncounted bar pot carries');
        $this->assertSame(1000, $second->fees_opening_cents->cents);
        $this->assertNotNull(TillSummary::uncountedSince($second, CashPot::BAR));

        // Another €5 at the bar tonight; the bar pot is finally counted: €10 accumulated, €9.50 found → −0.50.
        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'price_cents' => 500, 'stock' => 10]);
        (new CommitOrder)->handle($this->sede, [['article_id' => $article->id, 'qty' => 1]], ['operator_id' => $this->owner->id, 'till_session_id' => $second->id, 'cash_cents' => 500]);
        $closedAgain = (new CloseTill)->handle($second->fresh(), 10000, $this->owner, null, ['BAR' => 950]);
        $this->assertSame(1000, (int) $closedAgain->getRawOriginal('bar_expected_cents'));
        $this->assertSame(-50, (int) $closedAgain->getRawOriginal('bar_variance_cents'));

        $third = (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        $this->assertSame(950, $third->bar_opening_cents->cents, 'a counted pot opens with what was counted');
        $this->assertNull(TillSummary::uncountedSince($third, CashPot::BAR));
    }

    // --- 4. A movement out of the fees pot -----------------------------------------------------------------------------------------

    public function test_a_300_exit_from_the_fees_pot_reduces_only_fees(): void
    {
        $this->pots();
        $session = (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        $before = TillSummary::breakdown($session->fresh());

        (new RecordCashMovement)->handle($session, CashMovementType::OUT, 30000, ['reason' => 'A la caja fuerte', 'pot' => CashPot::FEES, 'operator_id' => $this->owner->id]);

        $after = TillSummary::breakdown($session->fresh());
        $this->assertSame($before['pots']['DISPENSARY']['expected'], $after['pots']['DISPENSARY']['expected']);
        $this->assertSame($before['pots']['BAR']['expected'], $after['pots']['BAR']['expected']);
        $this->assertSame($before['pots']['FEES']['expected'] - 30000, $after['pots']['FEES']['expected']);
    }

    // --- 5. Off: today's single drawer, unchanged (pin) ---------------------------------------------------------------------------

    public function test_with_the_setting_off_the_single_drawer_is_unchanged(): void
    {
        $this->pots(false);
        $session = (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        $this->trade($session);

        $this->assertFalse($session->fresh()->separate_pots);
        $this->assertSame(10000 + 2000 + 500 + 1000, TillSummary::expectedCents($session->fresh()));
        $closed = (new CloseTill)->handle($session->fresh(), 13500, $this->owner);
        $this->assertSame(0, $closed->variance_cents->cents);
        $this->assertNull($closed->getRawOriginal('bar_expected_cents'));
    }

    // --- 8. The reports' headline is the dispensary's -------------------------------------------------------------------------------

    public function test_the_z_report_the_dashboard_and_informes_cajas_use_the_dispensary_with_bar_and_fees_apart(): void
    {
        $this->pots();
        $session = (new OpenTill)->handle($this->sede, 'Caja 1', 10000, ['operator_id' => $this->owner->id]);
        $this->trade($session);
        (new CloseTill)->handle($session->fresh(), 11900, $this->owner, 'Faltaba un euro', ['FEES' => 1000]);

        $z = ZReport::forMany(collect([$session->fresh()]))[$session->id];
        $this->assertSame(12000, $z['expected']);
        $this->assertSame(-100, $z['variance']);
        $this->assertSame(500, $z['bar_expected']);
        $this->assertNull($z['bar_counted']);
        $this->assertSame(1000, $z['fees_counted']);
        $this->assertSame(0, $z['fees_variance']);

        $this->assertSame(-100, (new Dashboard($this->org->id, [$this->sede->id], Period::today()))->lastSessionVarianceCents());

        $report = (new TillReport($this->org->id, [$this->sede->id], Period::today()));
        $table = collect($report->tables())->firstWhere('key', 'sessions');
        $row = $table->rows[0];
        $this->assertSame(12000, $row['expected']);
        $this->assertSame(__('no contado'), $row['barra_contado']);
        $this->assertSame(500, $row['barra_esperado']);
    }
}
