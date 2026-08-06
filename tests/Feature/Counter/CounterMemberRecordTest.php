<?php

namespace Tests\Feature\Counter;

use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 177 — look a member up and see who they are, not just what they owe.
 *
 * Prompt 127 kept Socios deliberately small — collect a fee, see what is owed — and put renewals, tier
 * changes, suspensions and limits in the admin panel where they carry real authorisation weight. **That
 * boundary is not moved.** What is added is READING: telling a socio when their membership expires or what
 * they collected last week is not an authorisation-weighted act, it is the most ordinary question asked at
 * a counter, and today answering it meant leaving the counter.
 *
 * The assertions that matter most are the ones that stop this becoming a second read model, and the ones
 * that keep Article 9 material off a screen in a public-facing room.
 */
class CounterMemberRecordTest extends TestCase
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

    private function operator(Role $role = Role::MANAGER, string $terminal = 'POS-1'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);
        if (! TillSession::query()->withoutGlobalScopes()->where('terminal', $terminal)->exists()) {
            (new OpenTill)->handle($this->location, $terminal, 10000);
        }

        return $user;
    }

    private function member(int $feeCents = 2500, ?MembershipStatus $status = null): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'first_name' => 'Lucía', 'last_name' => 'García',
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 500, 'monthly_limit_cg' => 10000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'General']);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => $status ?? MembershipStatus::ACTIVE,
            'fee_cents' => $feeCents, 'expires_at' => now()->addMonths(3),
        ]);

        return $member;
    }

    private function screen(?Member $member = null): Testable
    {
        $component = Livewire::test(MembershipCounter::class);

        return $member === null ? $component : $component->call('selectFeeMember', $member->id);
    }

    // --- the record shows what a counter is asked ------------------------------------------------------

    public function test_a_member_shows_tier_expiry_what_is_owed_and_their_standing(): void
    {
        $this->operator();
        $member = $this->member();

        $html = $this->screen($member)->html();

        $this->assertStringContainsString('data-member-record', $html);
        $this->assertStringContainsString('General', $html);                                  // tier
        $this->assertStringContainsString(now()->addMonths(3)->format('d/m/Y'), $html);       // expiry
        $this->assertStringContainsString('data-member-allowance', $html);                    // daily + monthly
        $this->assertStringContainsString(__('Restante hoy'), $html);
        $this->assertStringContainsString(__('Monedero'), $html);
        $this->assertStringContainsString(__('Carencia'), $html);
    }

    /**
     * The assertion that stops a second read model appearing: the limit figures are compared against the
     * RESOLVER, not against hard-coded strings. If this screen ever computes its own, this fails.
     */
    public function test_every_limit_figure_matches_the_resolver(): void
    {
        $this->operator();
        $member = $this->member();

        $component = $this->screen($member);
        $limits = (new ResolveMemberLimits)->handle($member->fresh(), $this->location);

        $html = $component->html();
        $this->assertStringContainsString($component->instance()->grams($limits->dailyRemainingCg()), $html);
        $this->assertStringContainsString($component->instance()->grams($limits->monthlyLimitCg), $html);
    }

    public function test_the_owed_figure_comes_from_the_existing_fee_logic(): void
    {
        $this->operator();
        $member = $this->member(feeCents: 2500);

        $component = $this->screen($member);

        // `owedCents` is a VIEW variable, not a component property, so it is asserted where it is shown —
        // formatted by the component's own helper, so the test cannot drift from the display rule.
        $this->assertStringContainsString($component->instance()->money(2500), $component->html());
        $this->assertStringContainsString(__('Pendiente'), $component->html());
    }

    public function test_the_verdict_is_shown_so_a_member_can_ask_before_being_refused(): void
    {
        $this->operator();
        $member = $this->member();
        $member->update(['carencia_ends_at' => now()->addMonth()]);

        $html = $this->screen($member)->html();

        $this->assertStringContainsString('data-member-verdict', $html);
        $this->assertStringContainsString(__('Motivos que pueden impedir dispensar'), $html);
    }

    // --- collections: closed by default, bound to their member -----------------------------------------

    public function test_the_collection_history_is_closed_by_default(): void
    {
        $this->operator();
        $member = $this->member();

        $html = $this->screen($member)->html();

        // Article 9 data, on a screen with the next socio behind them. The summary is enough by default.
        $this->assertStringContainsString('data-history-toggle', $html);
        $this->assertStringNotContainsString('data-member-history', $html);
    }

    public function test_one_tap_opens_it(): void
    {
        $this->operator();
        $member = $this->member();

        $html = $this->screen($member)->call('toggleHistory')->html();

        $this->assertStringContainsString('data-member-history', $html);
    }

    public function test_changing_socio_closes_the_history_by_itself(): void
    {
        $this->operator();
        $first = $this->member();
        $second = $this->member();

        $component = $this->screen($first)->call('toggleHistory');
        $this->assertStringContainsString('data-member-history', $component->html());

        // One socio's collections must never be on screen while the next one is being served — and this must
        // not depend on the caller remembering to reset it.
        $component->call('selectFeeMember', $second->id);
        $this->assertStringNotContainsString('data-member-history', $component->html());

        $component->call('clearFeeMember');
        $this->assertFalse($component->instance()->historyIsForCurrentMember());
    }

    public function test_a_member_with_no_collections_gets_a_real_state_not_a_blank(): void
    {
        $this->operator();
        $member = $this->member();

        $html = $this->screen($member)->call('toggleHistory')->html();

        $this->assertStringContainsString(__('Todavía no ha recogido nada en esta sede.'), $html);
    }

    // --- the empty / edge states are designed ----------------------------------------------------------

    public function test_a_member_with_no_membership_renders_a_real_state(): void
    {
        $this->operator();
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30),
        ]);

        $html = $this->screen($member)->html();

        $this->assertStringContainsString(__('Sin membresía activa en esta sede.'), $html);
        $this->assertStringContainsString('data-member-record', $html);
    }

    public function test_an_expired_membership_still_renders_the_record(): void
    {
        $this->operator();
        $member = $this->member(status: MembershipStatus::LAPSED);

        $html = $this->screen($member)->html();

        $this->assertStringContainsString('data-member-record', $html);
        $this->assertStringContainsString(__('Sin membresía activa en esta sede.'), $html);
    }

    // --- Article 9: documents stay unreachable ----------------------------------------------------------

    public function test_no_document_dni_or_scan_is_reachable_from_this_screen_by_any_role(): void
    {
        foreach ([Role::OWNER, Role::MANAGER, Role::STAFF] as $i => $role) {
            $this->operator($role, terminal: 'POS-'.$i);
            $member = $this->member();
            $member->update(['document_number' => '12345678Z', 'document_scan_path' => 'member-id-scans/x.jpg']);

            $html = $this->screen($member->fresh())->call('toggleHistory')->html();

            // A DNI is Article 9-adjacent and its scan is behind a signed, logged link. Neither belongs on a
            // counter tablet, and the OWNER is included deliberately — permission is not the question here.
            $this->assertStringNotContainsString('12345678Z', $html, "{$role->value} can see the DNI");
            $this->assertStringNotContainsString('document_scan', $html, "{$role->value} can see the scan path");
            $this->assertStringNotContainsString('member-id-scans', $html, "{$role->value} can see the scan path");
            $this->assertStringNotContainsString('medical_cert', $html, "{$role->value} can see the medical cert");
        }
    }

    // --- read-only: no write path but the fee ----------------------------------------------------------

    public function test_nothing_on_this_screen_can_change_a_member_except_the_fee(): void
    {
        $methods = collect((new \ReflectionClass(MembershipCounter::class))->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->map(fn (\ReflectionMethod $m): string => $m->getName())
            ->reject(fn (string $n): bool => str_starts_with($n, '__'))
            ->values();

        // Prompt 127's boundary, asserted: no renewals, no tier changes, no suspensions, no limit overrides.
        foreach (['renew', 'suspend', 'setTier', 'changeTier', 'expel', 'setLimit', 'overrideLimit', 'updateMember', 'saveMember'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods->all(), "a write path appeared: $forbidden");
        }

        // The one write is the shared fee concern, unchanged.
        $this->assertContains('collectFee', $methods->all());
    }

    public function test_a_fee_collected_here_is_still_identical_to_one_collected_at_the_till(): void
    {
        $this->operator();
        $member = $this->member(feeCents: 2500);

        $this->screen($member)->set('feeAmount', '25,00')->set('feeMethod', 'CASH')->call('collectFee');

        // Same single writer (RecordFeePayment) as the till path — prompt 127's guarantee, still holding.
        $payment = MembershipFeePayment::query()->withoutGlobalScopes()->sole();
        $this->assertSame(2500, (int) DB::table('membership_fee_payments')->value('amount_cents'));
        $this->assertSame($member->fresh()->memberships()->withoutGlobalScopes()->latest('id')->first()->id, $payment->membership_id);
    }

    public function test_the_screen_is_still_gated_on_the_fee_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        // No role at all → no membership.fee.collect → 403, exactly as prompt 127 left it. Asserted through
        // the ROUTE, which is the established pattern here (MembershipCounterTest) and what a person hits.
        $this->get(route('counter.members'))->assertForbidden();
    }
}
