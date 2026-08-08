<?php

namespace Tests\Browser;

use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Till\OpenTill;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\MembershipCounter;
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
use Livewire\Livewire;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 219 — the fee panel with the waive control, and the door once the fee is waived.
 *
 * Two frames because the point is a pair: the control the owner asked for, and the notice it removes.
 */
class FeeWaiverHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_waiver_control_and_the_cleared_door(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro', 'capacity' => 50]);
        app(ActiveScope::class)->setLocation($location->id);

        $user = User::factory()->create(['name' => 'Lucía Márquez']);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($location, 'POS-1', 10000);

        // A therapeutic member — so the record-backed reason is the offered default, which is the case the
        // owner named first.
        $member = Member::factory()->create([
            'organisation_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'status' => MemberStatus::ACTIVE, 'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subDay(), 'is_therapeutic' => true,
        ]);
        $membership = Membership::factory()->create([
            'organisation_id' => $org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $org->id, 'default_fee_cents' => 2000])->id,
            'status' => MembershipStatus::ACTIVE,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(), 'fee_cents' => 2000,
        ]);

        $shell = function (string $route, string $html) {
            $page = (string) $this->get($route)->assertOk()->getContent();
            $open = (int) strpos($page, '<main');
            $close = (int) strrpos($page, '</main>');

            return substr($page, 0, (int) strpos($page, '>', $open) + 1).$html.substr($page, $close);
        };

        // 1) Socios, with the waiver open on its record-backed reason.
        $panel = Livewire::test(MembershipCounter::class)
            ->call('selectMember', $member->id)
            ->call('toggleWaive')
            ->html();
        file_put_contents(storage_path('app/waiver-219-panel.html'), $this->inlineBuiltCss($shell(route('counter.members'), $panel)));

        // 2) The door BEFORE — the fee nag.
        $before = Livewire::test(CheckInScreen::class)->call('selectMember', $member->id)->html();
        file_put_contents(storage_path('app/waiver-219-door-owing.html'), $this->inlineBuiltCss($shell(route('counter.checkin'), $before)));

        // 3) The door AFTER — waived, notice gone.
        (new RecordFeePayment)->handle($membership, 2000, FeePaymentMethod::WAIVED, [
            'operator_id' => $user->id, 'reason' => 'Terapéutico',
        ]);
        $after = Livewire::test(CheckInScreen::class)->call('selectMember', $member->id)->html();
        file_put_contents(storage_path('app/waiver-219-door-clear.html'), $this->inlineBuiltCss($shell(route('counter.checkin'), $after)));

        $this->assertStringContainsString('data-fee-waive-form', $panel);
        $this->assertStringContainsString('Cobrar cuota pendiente', $before);
        $this->assertStringNotContainsString('Cobrar cuota pendiente', $after, 'the fee notice survived the waiver');
    }
}
