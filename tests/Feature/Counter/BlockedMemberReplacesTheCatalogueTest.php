<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\EligibilityRule;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
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
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 225 — a present-but-blocked socio replaces the selling surface.
 *
 * The owner: *"If the member is blocked it should just hide the dispensary completely and say the fee is due
 * — take it or waive it — and record it."*
 *
 * Until this branch, a socio whose verdict BLOCKS got the whole catalogue, a working weight pad and a basket
 * they could fill, beside a warning that none of it could be committed. That is 175's philosophy inverted:
 * a blocking state REPLACES the work. Every other precondition on this screen already did it — no sede, no
 * till, no member each block the pane — and the one the operator can actually resolve from here did not.
 *
 * The gate is not becoming a picture: `commitDispensation()` refuses this member server-side exactly as
 * before, which the last test here pins.
 */
class BlockedMemberReplacesTheCatalogueTest extends TestCase
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

    private function operator(Role $role = Role::MANAGER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    private function genetic(): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Haze']);
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

    /** @param  array<string, mixed>  $membership */
    private function member(array $membership = []): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);

        Membership::factory()->create(array_merge([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ], $membership));

        return $member;
    }

    /** A socio blocked by an unpaid fee — the owner's own case. */
    private function owesAFee(): Member
    {
        return $this->member(['fee_cents' => 2500]);
    }

    private function pos(Member $member): Testable
    {
        return Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
    }

    // --- The surface ------------------------------------------------------------------------------

    /**
     * **A blocked socio gets no catalogue and no weight pad — and the resolution for their rule.**
     *
     * Fails against `f12b015`, where the full catalogue renders beside the warning.
     */
    public function test_a_blocked_member_replaces_the_catalogue_with_its_resolution(): void
    {
        $this->operator();
        $this->genetic();

        $html = $this->pos($this->owesAFee())->html();

        $this->assertStringContainsString('data-blocked-member', $html, 'the blocked surface did not render');
        $this->assertStringNotContainsString('data-product', $html, 'the catalogue is still sellable to a blocked socio');
        $this->assertStringNotContainsString('data-weight-preset', $html, 'the weight pad is still on screen');

        // …and the way out of it is here.
        $this->assertStringContainsString('data-blocked-resolution="unpaid_fee"', $html);
        $this->assertStringContainsString(e(__('Cobrar cuota')), $html, 'the fee cannot be collected from the block');
        $this->assertStringContainsString('data-fee-waive-toggle', $html, '219\'s waiver is not offered');
    }

    /** A clear socio is unaffected: the catalogue is exactly where it was. */
    public function test_a_clear_member_still_gets_the_catalogue(): void
    {
        $this->operator();
        $this->genetic();

        $html = $this->pos($this->member())->html();

        $this->assertStringNotContainsString('data-blocked-member', $html);
        $this->assertStringContainsString('data-product', $html);
    }

    /**
     * **Resolving it returns the catalogue in the same session, with the basket intact.**
     *
     * The basket is built BEFORE the fee is collected, which is the awkward order on purpose: a fee that
     * falls due mid-visit must not cost the operator the lines they had already rung up.
     */
    public function test_collecting_the_fee_returns_the_catalogue_with_the_basket_intact(): void
    {
        $this->operator();
        $genetic = $this->genetic();
        $member = $this->member();

        // A basket first, while they are clear…
        $component = $this->pos($member)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine');

        $this->assertCount(1, $component->get('basket'));

        // …then the fee falls due, and the surface takes over without touching the basket.
        // A re-render, NOT a re-selection: `selectMember` clears the basket by design, and the case being
        // tested is a fee that falls due under an operator who has already rung lines up.
        Membership::query()->withoutGlobalScopes()->where('member_id', $member->id)->update(['fee_cents' => 2500]);
        $component->call('$refresh');

        $this->assertStringContainsString('data-blocked-member', $component->html(), 'a mid-visit block did not take over');
        $this->assertCount(1, $component->get('basket'), 'the block emptied a basket built before it');

        // Collect it, from the surface, and the dispensary comes back.
        $component->set('feeAmount', '25')->set('feeMethod', 'CASH')->call('collectMemberFee');

        $html = $component->html();
        $this->assertStringNotContainsString('data-blocked-member', $html, 'the catalogue did not come back');
        $this->assertStringContainsString('data-product', $html);
        $this->assertCount(1, $component->get('basket'), 'resolving the block lost the basket');
    }

    /** Waiving it does the same — 219's path, from the same surface. */
    public function test_waiving_the_fee_also_returns_the_catalogue(): void
    {
        $this->operator();
        $this->genetic();
        $member = $this->owesAFee();

        $component = $this->pos($member);
        $this->assertStringContainsString('data-blocked-member', $component->html());

        $component->call('toggleWaive')->set('waiveReason', 'OTHER')->set('waiveReasonText', 'Caso social')->call('waiveFee');

        $this->assertStringNotContainsString('data-blocked-member', $component->html(), 'a waived fee still blocks the dispensary');
    }

    /**
     * **A rule with no counter-side fix renders its explanation and no dead control** — iterated over the
     * rules rather than asserted for the one we happened to build (211's pattern).
     */
    public function test_a_rule_with_no_counter_action_offers_no_control(): void
    {
        $this->operator();
        $this->genetic();
        $member = $this->member();

        // The `sanction` rule reads the member's STATUS, not a sanction row — one resolver, one source of
        // truth (ResolveMemberEligibility:31). A suspended socio is the case with no counter-side fix.
        $member->forceFill(['status' => MemberStatus::SUSPENDED])->save();

        $html = $this->pos($member)->html();

        $this->assertStringContainsString('data-blocked-member', $html);
        $this->assertStringContainsString('data-blocked-reasons', $html, 'the reason is not stated');

        // Every rule with no counter action gets no resolution block, whatever it is.
        foreach (EligibilityRule::cases() as $rule) {
            if ($rule->hasCounterAction()) {
                continue;
            }

            $this->assertStringNotContainsString(
                'data-blocked-resolution="'.$rule->value.'"',
                $html,
                "{$rule->value} has no counter-side fix and was given a control anyway",
            );
        }
    }

    // --- What must NOT change ---------------------------------------------------------------------

    /** The Barra still sells to a blocked socio — no MEMBER step in its chain, by design. */
    public function test_the_bar_screen_still_sells_to_a_blocked_member(): void
    {
        $this->operator();
        Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => 'Cerveza', 'price_cents' => 250, 'stock' => 100, 'active' => true,
        ]);

        $html = Livewire::test(BarPos::class)->call('selectMember', $this->owesAFee()->id)->html();

        $this->assertStringNotContainsString('data-blocked-member', $html, 'the bar screen blocked a coffee');
        $this->assertStringContainsString('data-product', $html, 'the bar catalogue disappeared');
    }

    /** The server still refuses, and still says why: the gate did not become a picture. */
    public function test_the_server_still_refuses_a_blocked_commit(): void
    {
        $this->operator();
        $genetic = $this->genetic();
        $member = $this->member();

        $component = $this->pos($member)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine');

        Membership::query()->withoutGlobalScopes()->where('member_id', $member->id)->update(['fee_cents' => 2500]);

        $component->call('$refresh')->call('commitDispensation');

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count(), 'a blocked commit went through');
        $this->assertNotNull($component->get('flashMessage'), 'the refusal said nothing');
    }

    /** The block is stated ONCE on the screen, and announced once (prompt 199). */
    public function test_the_block_is_stated_exactly_once(): void
    {
        $this->operator();
        $this->genetic();

        $html = $this->pos($this->owesAFee())->html();

        $this->assertSame(1, substr_count($html, 'data-blocked-reasons'), 'the reasons are listed twice');
        $this->assertSame(1, substr_count($html, 'data-commit-blocked-reason'), 'the commit reminder renders twice');
        $this->assertSame(1, substr_count($html, 'aria-live="polite"'), 'the block is announced more than once');
        // …and the fee panel is not ALSO in the cart column beside it.
        $this->assertSame(1, substr_count($html, 'data-fee-waive-toggle'), 'the fee panel renders twice');
    }

    // --- The column and the catalogue's density ----------------------------------------------------

    /** The commit carries the total, and the reason line only when blocked. */
    public function test_the_commit_button_carries_the_total(): void
    {
        $this->operator();
        $genetic = $this->genetic();

        $html = $this->pos($this->member())
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine')
            ->html();

        $this->assertStringContainsString(e(Money::fromCents(1600)->formatted()), $html, 'the total is not on the button');
        $this->assertStringNotContainsString('data-commit-blocked-reason', $html, 'a clear socio was told they are blocked');
    }

    /** Only the cart's middle region scrolls, and it is marked as the region that does. */
    public function test_only_the_cart_middle_is_a_scroll_region(): void
    {
        $this->operator();

        $html = $this->pos($this->member())->html();

        $this->assertStringContainsString('data-cart-scroll', $html);
        $this->assertStringContainsString('counter-scroll-region', $html, 'the scroll region carries no affordance');
    }

    /** List for genetics, grid for the bar — two preferences, one toggle. */
    public function test_each_source_keeps_its_own_layout(): void
    {
        $this->operator();
        $this->genetic();

        $component = $this->pos($this->member());

        $this->assertSame('list', $component->get('geneticLayout'));
        $this->assertSame('grid', $component->get('articleLayout'));

        // Switching the BAR to list must not touch the genetics preference.
        $component->call('setCatalogueSource', 'bar')->call('setGeneticLayout', 'list');

        $this->assertSame('list', $component->get('articleLayout'));
        $this->assertSame('list', $component->get('geneticLayout'));

        $component->call('setCatalogueSource', 'genetics')->call('setGeneticLayout', 'grid');

        $this->assertSame('grid', $component->get('geneticLayout'), 'the genetics layout did not change');
        $this->assertSame('list', $component->get('articleLayout'), 'changing the genetics layout changed the bar\'s');
    }
}
