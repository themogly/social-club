<?php

namespace Tests\Feature\Security;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 254 — the handed-over tablet can be given back, and while it is out the counter answers nothing else.
 *
 * Every request here goes through the HTTP kernel to Livewire's REAL update endpoint, the way the browser sends
 * it. On `c2946db` the PIN's post was answered `302 → /socio/solicitud/<token>` by `EnforceCounterHandover`
 * (its allowlist said `livewire/*`; Livewire 4 posts to `livewire-<hash>/update`), so the handover could never
 * end — and 249's `Livewire::test()` suite, which skips HTTP middleware, could not see it. Allowing the path
 * alone then exposed the member register to the applicant, so the other half of this file is the confinement.
 */
class HandoverConfinementOverHttpTest extends TestCase
{
    use PostsLivewireOverHttp, RefreshDatabase;

    private const PIN = '2468';

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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->location->id);

        // The runbook's install: the tablet logged in once as the owner; a manager works it by PIN.
        $this->device = $this->user('Tablet Owner', Role::OWNER, null);
        $this->operator = $this->user('Marta Manager', Role::MANAGER, self::PIN);

        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    private function user(string $name, Role $role, ?string $pin): User
    {
        $user = User::factory()->create(['name' => $name, 'pin' => $pin === null ? null : Hash::make($pin)]);
        $user->assignRole($role->value);
        $user->locations()->attach($this->location->id);

        return $user;
    }

    /**
     * Sign in, choose the sede and identify — all through real requests — then hand the tablet over from the
     * alta modal's real entry point. Returns the tokenised form URL the applicant was sent to.
     */
    private function handOverThroughHttp(): string
    {
        $this->actingAs($this->device);
        $this->post(route('counter.location'), ['location_id' => $this->location->id])->assertRedirect();

        $this->identifyThroughHttp('/counter/members', 'counter.membership-counter');
        $this->assertNotNull(CounterOperator::id(), 'precondition: the PIN identified the operator');

        $response = $this->livewirePost(
            $this->snapshotFrom('/counter/members', 'counter.membership-counter'),
            calls: [['handOverForAlta']],
        )->assertOk();

        $this->assertTrue(CounterHandover::active(), 'precondition: the tablet is handed over');
        $returnUrl = CounterHandover::returnUrl();
        $this->assertNotNull($returnUrl);
        $this->assertStringContainsString('/socio/solicitud/', (string) data_get($response->json(), 'components.0.effects.redirect'));

        return $returnUrl;
    }

    private function identifyThroughHttp(string $uri, string $component): TestResponse
    {
        return $this->livewirePost(
            $this->snapshotFrom($uri, $component),
            ['operatorPin' => self::PIN],
            [['unlockOperator']],
        );
    }

    private function refusedCallsLogged(): int
    {
        return AuditLog::query()->where('action', 'counter.handover.refused_call')->count();
    }

    // --- 1. The PIN ends a handover ----------------------------------------------------------------------

    public function test_the_pin_ends_an_unsubmitted_handover_through_the_real_endpoint(): void
    {
        $formUrl = $this->handOverThroughHttp();
        $this->get('/')->assertRedirect($formUrl);

        $response = $this->identifyThroughHttp('/counter/checkin', 'counter.check-in-screen');

        $response->assertOk();
        $this->assertFalse(CounterHandover::active(), 'The PIN did not end the handover.');
        $this->assertSame($this->operator->id, CounterOperator::id());
        $this->assertTrue(AuditLog::query()->where('action', 'counter.handover.ended')->exists());

        // And the tablet is the counter's again — typing `/` no longer lands on the applicant's form.
        $this->get('/')->assertOk();
    }

    public function test_the_pin_ends_a_submitted_handover_and_lands_on_the_review(): void
    {
        $this->handOverThroughHttp();
        $application = MemberApplication::query()->latest('id')->firstOrFail();
        CounterHandover::markSubmitted($application->id);

        $response = $this->identifyThroughHttp('/counter/checkin', 'counter.check-in-screen');

        $response->assertOk();
        $this->assertFalse(CounterHandover::active());
        $this->assertSame($this->operator->id, CounterOperator::id());
        // 249's rule survives: the operator is carried to the review of the application just submitted.
        $this->assertSame(
            route('counter.members', ['alta' => $application->id]),
            data_get($response->json(), 'components.0.effects.redirect'),
        );
    }

    // --- 2. The applicant cannot read the register -------------------------------------------------------

    public function test_the_applicant_cannot_search_the_member_register_on_any_counter_screen(): void
    {
        Member::factory()->create([
            'organisation_id' => $this->org->id,
            'first_name' => 'Ainhoa',
            'last_name' => 'Zubizarreta',
            'member_no' => 'M-77123',
            'document_number' => '99887766Q',
        ]);

        // Positive control: with an operator working, the SAME request does return the member — so the
        // refusal below is the confinement, not a request that could never have leaked.
        $this->actingAs($this->device);
        $this->post(route('counter.location'), ['location_id' => $this->location->id]);
        $this->identifyThroughHttp('/counter/members', 'counter.membership-counter');
        // Since prompt 260 `lookupResults` is not a wire action and returns no DNI at all — the control is the
        // rendered result row (the surname), which is what the lookup shows a working operator.
        $control = $this->livewirePost($this->snapshotFrom('/counter/members', 'counter.membership-counter'),
            ['lookup' => 'Zubi']);
        $this->assertStringContainsString('Zubizarreta', (string) $control->getContent(), 'control: unconfined, the lookup renders the planted member');
        CounterOperator::clear();

        $this->handOverThroughHttp();

        foreach ([
            '/counter/members' => 'counter.membership-counter',
            '/counter/pos' => 'counter.dispensary-pos',
            '/counter/checkin' => 'counter.check-in-screen',
            '/counter/bar' => 'counter.bar-pos',
        ] as $uri => $component) {
            $response = $this->livewirePost($this->snapshotFrom($uri, $component), ['lookup' => 'Zubi'], [['lookupResults']]);

            $response->assertForbidden();
            $body = (string) $response->getContent();
            foreach (['Zubizarreta', 'M-77123', '99887766Q'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "{$component} leaked {$secret} to the applicant.");
            }
            $this->assertTrue(CounterHandover::active(), 'A refused call must not end or alter the handover.');
        }

        // Audit-logged, naming the component and the property/method — never the payload.
        $this->assertSame(4, $this->refusedCallsLogged());
        $entry = AuditLog::query()->where('action', 'counter.handover.refused_call')->latest('id')->firstOrFail();
        $this->assertSame('counter.bar-pos', $entry->after['component'] ?? null);
        $this->assertStringNotContainsString('Zubi', json_encode($entry->after));
    }

    // --- 3. Nor pending applications ---------------------------------------------------------------------

    public function test_the_applicant_cannot_read_pending_applications(): void
    {
        $this->handOverThroughHttp();
        $snapshot = $this->snapshotFrom('/counter/members', 'counter.membership-counter');

        foreach (['altaApplication', 'pendingAltaApplications'] as $method) {
            $this->livewirePost($snapshot, calls: [[$method]])->assertForbidden();
        }

        $this->assertSame(2, $this->refusedCallsLogged());
    }

    // --- 4. Nor a write ----------------------------------------------------------------------------------

    public function test_the_applicant_cannot_close_the_till(): void
    {
        $this->handOverThroughHttp();

        $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'),
            calls: [['startClose'], ['submitCount']])->assertForbidden();

        $this->assertSame(TillSessionStatus::OPEN, TillSession::query()->sole()->status);
        $this->assertTrue(CounterHandover::active());
    }

    public function test_a_non_counter_component_is_refused_during_a_handover(): void
    {
        // A panel component's snapshot, taken while the tablet was the counter's.
        $this->actingAs($this->device);
        $html = (string) $this->get('/')->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'precondition: the dashboard renders a Livewire component');
        $snapshot = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        $this->assertStringStartsNotWith('counter.', json_decode($snapshot, true)['memo']['name']);

        $this->handOverThroughHttp();

        // Even a bare re-render: the panel answers nothing while an applicant holds the tablet.
        $this->livewirePost($snapshot)->assertForbidden();
    }

    // --- 5. The idle timeout still ends it ---------------------------------------------------------------

    public function test_the_idle_lock_event_still_ends_a_handover(): void
    {
        $this->handOverThroughHttp();

        // The layout's idle timer: `Livewire.dispatch('counter-lock')` reaches every listener as `__dispatch`.
        $this->livewirePost($this->snapshotFrom('/counter/checkin', 'counter.check-in-screen'),
            calls: [['__dispatch', ['counter-lock', []]]])->assertOk();

        $this->assertFalse(CounterHandover::active());
        $this->assertTrue(AuditLog::query()->where('action', 'counter.handover.timed_out')->exists());
    }

    public function test_the_chrome_may_hear_the_lock_but_nothing_else(): void
    {
        $this->handOverThroughHttp();
        $snapshot = $this->snapshotFrom('/counter/checkin', 'counter.counter-chrome');

        $this->livewirePost($snapshot, calls: [['__dispatch', ['counter-switch-operator', []]]])->assertForbidden();
        $this->livewirePost($snapshot, calls: [['__dispatch', ['counter-lock', []]]])->assertOk();
    }

    public function test_only_the_surface_traffic_answers_a_counter_screen(): void
    {
        $this->handOverThroughHttp();
        $snapshot = $this->snapshotFrom('/counter/checkin', 'counter.check-in-screen');

        // A bare re-render is the surface's own traffic.
        $this->livewirePost($snapshot)->assertOk();
        // A wrong PIN is an allowed call that simply fails — the handover stays.
        $this->livewirePost($snapshot, ['operatorPin' => '0000'], [['unlockOperator']])->assertOk();
        $this->assertTrue(CounterHandover::active());

        // Anything else — another event, a direct lock, the operator panel, `$refresh` — is refused.
        foreach ([
            [['__dispatch', ['counter-switch-operator', []]]],
            [['lockCounter']],
            [['openOperatorPanel']],
            [['$refresh']],
        ] as $calls) {
            $this->livewirePost($snapshot, calls: $calls)->assertForbidden();
        }
        $this->livewirePost($snapshot, ['operatorFeedback' => 'x'])->assertForbidden();
        $this->assertTrue(CounterHandover::active());
    }

    // --- 7. Outside a handover nothing changed -----------------------------------------------------------

    public function test_outside_a_handover_the_counter_answers_as_before(): void
    {
        $this->actingAs($this->device);
        $this->post(route('counter.location'), ['location_id' => $this->location->id]);
        $this->identifyThroughHttp('/counter/checkin', 'counter.check-in-screen')->assertOk();

        $this->livewirePost($this->snapshotFrom('/counter/checkin', 'counter.check-in-screen'),
            ['lookup' => 'Zu'], [['openOperatorPanel']])->assertOk();
        $this->assertSame(0, $this->refusedCallsLogged());
    }
}
