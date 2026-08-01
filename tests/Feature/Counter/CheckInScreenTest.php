<?php

namespace Tests\Feature\Counter;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Members\IssueMemberToken;
use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\CheckInScreen;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CheckInScreenTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 10]);
    }

    /** A staff operator assigned to the sede, with the active location set. */
    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value); // STAFF holds checkin.manage
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user); // PIN-identified operator (prompt 26 guard)

        return $user;
    }

    private function eligibleMember(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30),
            'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Standard']);
        Membership::factory()->create([
            'organisation_id' => $this->org->id,
            'member_id' => $member->id,
            'location_id' => $this->location->id,
            'tier_id' => $tier->id,
            'status' => MembershipStatus::ACTIVE,
            'fee_cents' => 0, // no fee outstanding
        ]);

        return $member;
    }

    /** An eligible member whose ACTIVE membership carries an unpaid fee at this sede (door flags unpaid_fee). */
    private function memberOwing(int $feeCents = 2000): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30),
            'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Standard']);
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

    public function test_the_door_collects_an_outstanding_fee_inline_and_clears_the_verdict(): void
    {
        // Prompt 127 Part 2 — the fee action follows the unpaid-fee verdict onto the door. A blank amount
        // collects the FULL owed balance through the SAME shared concern (RecordFeePayment).
        $operator = $this->operator();
        $this->actingAs($operator);
        app(ActiveScope::class)->setLocation($this->location->id);
        (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $operator->id]);
        $member = $this->memberOwing(2000);

        // Before: the door flags the unpaid fee.
        $before = collect((new ResolveMemberEligibility)->handle($member, $this->location, 'door')->rules)->firstWhere('rule', 'unpaid_fee');
        $this->assertFalse($before['satisfied']);

        Livewire::test(CheckInScreen::class)
            ->call('selectMember', $member->id)
            ->assertSee(__('Cobrar cuota pendiente'))
            ->call('collectMemberFee')
            ->assertSet('flashType', 'success');

        $this->assertSame(2000, (int) MembershipFeePayment::query()->sum('amount_cents'));

        // After: paid in full → the door no longer flags it (the inline action cleared the verdict).
        $after = collect((new ResolveMemberEligibility)->handle($member->fresh(), $this->location, 'door')->rules)->firstWhere('rule', 'unpaid_fee');
        $this->assertTrue($after['satisfied']);
    }

    public function test_an_inline_cash_fee_at_the_door_with_no_open_till_is_refused(): void
    {
        // The drawer-reconciliation invariant holds inline too: a CASH fee needs an open till.
        $this->actingAs($this->operator());
        app(ActiveScope::class)->setLocation($this->location->id);
        $member = $this->memberOwing(2000);

        Livewire::test(CheckInScreen::class)
            ->call('selectMember', $member->id)
            ->set('feeMethod', 'CASH')
            ->call('collectMemberFee')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, MembershipFeePayment::query()->count());
    }

    public function test_the_screen_renders_for_an_assigned_operator(): void
    {
        $this->actingAs($this->operator());
        app(ActiveScope::class)->setLocation($this->location->id);

        Livewire::test(CheckInScreen::class)
            ->assertOk()
            ->assertSet('noLocation', false)
            ->assertSee(__('Escanear tarjeta de socio'));
    }

    public function test_an_operator_with_no_location_gets_a_friendly_state_still_200(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value); // has permission, but no assigned sede
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation(null);

        Livewire::test(CheckInScreen::class)
            ->assertOk()
            ->assertSet('noLocation', true)
            ->assertSee(__('Sin sede asignada'));
    }

    public function test_scanning_a_members_token_surfaces_the_member_and_check_in_works(): void
    {
        $member = $this->eligibleMember();
        $token = (new IssueMemberToken)->handle($member);

        $this->actingAs($this->operator());
        app(ActiveScope::class)->setLocation($this->location->id);

        Livewire::test(CheckInScreen::class)
            ->set('scan', $token)
            ->call('submitScan')
            ->assertSet('memberId', $member->id)
            ->assertSee($member->fullName())
            ->assertSee($member->member_no)
            ->call('checkIn')
            ->assertSee(__('Registrar salida')); // now inside → button flips to check-out

        $this->assertDatabaseHas('check_ins', [
            'member_id' => $member->id,
            'location_id' => $this->location->id,
            'checked_out_at' => null,
        ]);
    }

    public function test_an_unknown_token_does_not_surface_a_member(): void
    {
        $this->actingAs($this->operator());
        app(ActiveScope::class)->setLocation($this->location->id);

        Livewire::test(CheckInScreen::class)
            ->set('scan', 'not-a-real-token')
            ->call('submitScan')
            ->assertSet('memberId', null)
            ->assertSee(__('Tarjeta no reconocida. Inténtalo de nuevo o busca por nombre.'));
    }

    public function test_the_fallback_search_finds_a_member_and_selecting_holds_them(): void
    {
        $member = $this->eligibleMember();

        $this->actingAs($this->operator());
        app(ActiveScope::class)->setLocation($this->location->id);

        Livewire::test(CheckInScreen::class)
            ->set('search', $member->last_name)
            ->assertSee($member->fullName())
            ->call('selectMember', $member->id)
            ->assertSet('memberId', $member->id);
    }

    public function test_a_user_without_checkin_manage_is_forbidden(): void
    {
        $user = User::factory()->create(); // no role → no checkin.manage
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->get(route('counter.checkin'))->assertForbidden();
    }
}
