<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Filament\Resources\Members\MemberResource;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 173's handover mode, ATTACKED rather than read (Phase C security audit, carried forward — the work
 * order asked for exactly this and nobody had done it).
 *
 * The threat model is explicit: a logged-in, sede-scoped counter terminal is placed in the hands of someone
 * who is not a member. 173 blanked the five counter screens. What it never closed was the SESSION behind
 * them — the device user stays authenticated with panel access, so the address bar, the back button or a
 * bookmark reached the whole admin panel. Measured before the fix: `GET /` returned 200 with the dashboard
 * and the member list returned 200 with a member's surname in the HTML.
 *
 * These are the denial tests for {@see \App\Http\Middleware\EnforceCounterHandover}. They assert both halves:
 * that the privileged surfaces are refused, AND that the four things the handover legitimately needs still
 * answer — because a gate that strands the tablet is its own kind of failure.
 */
class HandoverBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $device;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->device = $this->staff('Device Login', null);
        $this->operator = $this->staff('Marta Operadora', '4321');
    }

    private function staff(string $name, ?string $pin): User
    {
        $user = User::factory()->create(['name' => $name, 'pin' => $pin === null ? null : Hash::make($pin)]);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->attach($this->location->id);

        return $user;
    }

    private function handOver(): void
    {
        CounterOperator::set($this->operator);
        Livewire::actingAs($this->device)->test(TillSession::class)->call('beginHandover');
        $this->assertTrue(CounterHandover::active(), 'precondition: the tablet is handed over');
    }

    // --- What must be refused ------------------------------------------------------------------------

    public function test_the_admin_panel_dashboard_is_refused_during_a_handover(): void
    {
        $this->handOver();

        $this->actingAs($this->device)->get('/')->assertRedirect();
    }

    public function test_the_member_register_is_refused_and_leaks_no_member_during_a_handover(): void
    {
        Member::factory()->create([
            'organisation_id' => $this->org->id,
            'first_name' => 'Ana',
            'last_name' => 'Secreta',
        ]);

        $this->handOver();

        $response = $this->actingAs($this->device)->get(MemberResource::getUrl('index'));

        $response->assertRedirect();
        $this->assertStringNotContainsString('Secreta', $response->getContent(),
            'A member surname reached a tablet in an applicant\'s hands.');
    }

    public function test_a_contribution_receipt_is_refused_during_a_handover(): void
    {
        $this->handOver();

        // The top bar is absent from the DOM during a handover, so nothing legitimate reaches a receipt.
        $this->actingAs($this->device)
            ->get(route('counter.pos.receipt', ['dispensation' => '01jzzzzzzzzzzzzzzzzzzzzzzz']))
            ->assertRedirect();
    }

    public function test_the_panel_is_reachable_again_once_the_handover_ends(): void
    {
        $this->handOver();
        $this->actingAs($this->device)->get('/')->assertRedirect();

        CounterHandover::end();

        // Not bricked: ending the handover restores the device session's ordinary reach.
        $this->actingAs($this->device)->get('/')->assertOk();
    }

    // --- What must keep working ----------------------------------------------------------------------

    public function test_the_applicants_own_form_still_answers_during_a_handover(): void
    {
        $token = 'tok-'.str_repeat('a', 28);
        MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'invite_token' => $token,
            'invite_token_hash' => hash('sha256', $token),
        ]);

        $this->handOver();

        $this->get(route('socio.application', ['token' => $token]))->assertOk();
    }

    public function test_the_counter_screens_still_answer_so_the_pin_pad_is_reachable(): void
    {
        $this->handOver();

        foreach (['counter.checkin', 'counter.members', 'counter.till', 'counter.pos', 'counter.bar'] as $route) {
            $this->actingAs($this->device)->get(route($route))->assertOk();
        }
    }

    public function test_livewire_is_not_blocked_so_the_handover_can_be_ended(): void
    {
        $this->handOver();

        // The PIN pad posts to Livewire. Whatever Livewire makes of an empty payload, the one thing that must
        // NOT happen is the handover gate bouncing it — that would strand the tablet with no way back.
        $response = $this->actingAs($this->device)->post('/livewire/update', []);

        $this->assertNotSame(302, $response->getStatusCode(),
            'The handover gate blocked Livewire — the PIN pad is how the handover ends.');
    }
}
