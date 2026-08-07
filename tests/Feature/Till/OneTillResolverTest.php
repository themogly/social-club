<?php

namespace Tests\Feature\Till;

use App\Actions\Till\OpenTill;
use App\Actions\Till\SelectTillSession;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Livewire\Counter\CheckInScreen;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Code-style audit — ONE resolver for "which open caja is this counter posting to?".
 *
 * Before this there were four, in two versions: the two POS screens matched their own terminal and fell
 * back to the OLDEST open session; the door and Socios took `latest('opened_at')` — the NEWEST — with no
 * terminal at all. With one open till they agreed, which is why nothing ever caught it. With two, the same
 * cash membership fee posted to a different drawer depending on which screen took it.
 *
 * The tie-break is now stated once — oldest open first, terminal preferred when the caller has one — and
 * these tests pin it at the resolver AND through the two screens that changed.
 */
class OneTillResolverTest extends TestCase
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

    /** Two tills, opened in a known order — the case where the two old rules disagreed. */
    private function twoTills(): array
    {
        $first = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $first->forceFill(['opened_at' => now()->subHours(3)])->save();

        $second = (new OpenTill)->handle($this->location, 'BAR-1', 5000);
        $second->forceFill(['opened_at' => now()->subHour()])->save();

        return [$first->refresh(), $second->refresh()];
    }

    private function memberOwing(int $feeCents = 2500): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30),
            'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id,
            'member_id' => $member->id,
            'location_id' => $this->location->id,
            'tier_id' => $tier->id,
            'status' => MembershipStatus::ACTIVE,
            'fee_cents' => $feeCents,
        ]);

        return $member;
    }

    public function test_the_resolver_prefers_the_callers_terminal(): void
    {
        [$pos, $bar] = $this->twoTills();

        $this->assertSame($pos->id, (new SelectTillSession)->handle($this->location, 'POS-1')?->id);
        $this->assertSame($bar->id, (new SelectTillSession)->handle($this->location, 'BAR-1')?->id);
        // Normalised key, not the raw string (prompt 84) — a spelling variant still resolves.
        $this->assertSame($bar->id, (new SelectTillSession)->handle($this->location, 'bar 1')?->id);
    }

    public function test_without_a_terminal_it_takes_the_oldest_open_session(): void
    {
        [$pos] = $this->twoTills();

        $this->assertSame($pos->id, (new SelectTillSession)->handle($this->location)?->id, 'oldest open, stated once');
        $this->assertSame($pos->id, (new SelectTillSession)->handle($this->location, '')?->id, 'an empty terminal is no terminal');
    }

    public function test_an_unknown_terminal_resolves_to_nothing_rather_than_guessing(): void
    {
        $this->twoTills();

        $this->assertNull((new SelectTillSession)->handle($this->location, 'NOT-A-TILL'));
    }

    public function test_it_sees_only_open_sessions_at_this_sede(): void
    {
        [$pos, $bar] = $this->twoTills();
        $bar->forceFill(['status' => TillSessionStatus::CLOSED])->save();

        $this->assertSame([$pos->id], (new SelectTillSession)->openAt($this->location)->pluck('id')->all());

        $elsewhere = Location::factory()->create(['organisation_id' => $this->org->id]);
        (new OpenTill)->handle($elsewhere, 'OTHER-1', 100);

        $this->assertSame([$pos->id], (new SelectTillSession)->openAt($this->location)->pluck('id')->all());
    }

    /**
     * The behaviour that actually changed, through the screen that changed it.
     *
     * The door has no terminal, so it used to take the NEWEST open session; it now takes the same oldest-open
     * fallback the POS falls back to. On a two-till sede that is a different drawer — which is the point: one
     * rule, in one place, rather than two screens quietly disagreeing about where the money went.
     */
    public function test_a_cash_fee_at_the_door_posts_to_the_oldest_open_drawer(): void
    {
        $this->operator();
        [$pos, $bar] = $this->twoTills();
        $member = $this->memberOwing(2500);

        Livewire::test(CheckInScreen::class)
            ->call('selectMember', $member->id)
            ->set('feeAmount', '25.00')
            ->set('feeMethod', 'CASH')
            ->call('collectMemberFee')
            ->assertSet('flashType', 'success');

        $payment = MembershipFeePayment::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame(2500, $payment->amount_cents->cents, 'the real stored amount, in cents');
        $this->assertSame($pos->id, $payment->till_session_id, 'the oldest open drawer, not the newest');
        $this->assertNotSame($bar->id, $payment->till_session_id);
    }

    /** Socios takes the same fee through the same concern, so it must land in the same drawer. */
    public function test_socios_posts_a_cash_fee_to_the_same_drawer_as_the_door(): void
    {
        $this->operator();
        [$pos] = $this->twoTills();
        $member = $this->memberOwing(2500);

        Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '25.00')
            ->set('feeMethod', 'CASH')
            ->call('collectFee')
            ->assertSet('flashType', 'success');

        $this->assertSame($pos->id, MembershipFeePayment::query()->withoutGlobalScopes()->firstOrFail()->till_session_id);
    }

    /** The ordinary case — one open till — is unchanged, which is why nothing caught this for so long. */
    public function test_with_one_open_till_every_screen_agrees(): void
    {
        $only = (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $this->assertSame($only->id, (new SelectTillSession)->handle($this->location)?->id);
        $this->assertSame($only->id, (new SelectTillSession)->handle($this->location, 'POS-1')?->id);
        $this->assertSame(1, TillSession::query()->withoutGlobalScopes()->open()->count());
    }
}
