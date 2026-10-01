<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\IssueApplicationInvite;
use App\Enums\Role;
use App\Filament\Pages\Auth\Login;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use App\Support\MrzPrefill;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 342 — Ben closed the browser mid-handover, came back, logged in, and landed on the membership application with
 * no way out: "There needs to be a 'quit application' button somewhere." Staff end a handover with a PIN (anyone at that
 * sede); an abandoned one ends by itself; a password login ends it and never lands on an applicant route; an applicant
 * on their own phone can leave without sending.
 */
class HandoverWayOutTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private Location $otra;

    private User $owner;

    private User $staff;

    private MemberApplication $invite;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Dream Green']);
        $this->otra = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Green Indoor']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create(['email' => 'ben@club.test', 'password' => 'secret-pass', 'pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id, $this->otra->id]);
        $this->staff = User::factory()->create(['name' => 'Marta', 'pin' => '3456']);
        $this->staff->assignRole(Role::STAFF->value);
        $this->staff->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        session(['counter.location_id' => $this->sede->id]);
        $this->invite = (new IssueApplicationInvite)->handle($this->owner, $this->sede->id, null, 'PRUEBA-342');
        $this->token = (string) $this->invite->invite_token;
    }

    private function handOver(): void
    {
        CounterHandover::begin($this->owner->id, $this->sede->id, route('socio.application', ['token' => $this->token]));
        session(['counter.handover.draft' => ['first_name' => 'Ana']]);
        MrzPrefill::remember($this->token, ['first_name' => 'ANA']);
    }

    // --- 1. Staff end it with a PIN ------------------------------------------------------------------------------------------

    public function test_a_staff_pin_at_that_sede_ends_the_handover_and_lands_on_socios(): void
    {
        $this->handOver();
        $this->get(route('socio.application', ['token' => $this->token]))->assertOk()
            ->assertSee('data-handover-staff-exit', false)->assertSee(__('Personal'));

        $this->post(route('socio.application.staff', ['token' => $this->token]), ['pin' => '3456'])
            ->assertRedirect(route('counter.members'));

        $this->assertFalse(CounterHandover::active());
        $this->assertNull(session('counter.handover.draft'));
        $this->assertSame([], MrzPrefill::get($this->token));
        $this->assertNull($this->invite->fresh()->submitted_at, 'the invitation stays unsubmitted');
        $this->assertSame($this->staff->id, CounterOperator::id());
        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'counter.handover.cancelled')->sole();
        $this->assertSame($this->staff->id, $audit->after['operator_id'] ?? null);
        $this->assertStringNotContainsString('Ana', json_encode($audit->after) ?: '');
    }

    public function test_a_wrong_pin_counts_and_keeps_the_form_and_another_sedes_staff_are_refused(): void
    {
        $this->handOver();
        $this->post(route('socio.application.staff', ['token' => $this->token]), ['pin' => '9999'])
            ->assertRedirect(route('socio.application', ['token' => $this->token]))->assertSessionHas('handoverStaffError');
        $this->assertTrue(CounterHandover::active());
        $this->assertGreaterThan(0, (int) Cache::store(config('cache.limiter'))->get('counter-pin:'.$this->sede->id.':attempts', 0));

        $elsewhere = User::factory()->create(['pin' => '7777']);
        $elsewhere->assignRole(Role::STAFF->value);
        $elsewhere->locations()->sync([$this->otra->id]);
        $this->post(route('socio.application.staff', ['token' => $this->token]), ['pin' => '7777'])->assertSessionHas('handoverStaffError');
        $this->assertTrue(CounterHandover::active());
    }

    // --- 2. Abandoned: it ends by itself ----------------------------------------------------------------------------------------

    public function test_a_handover_idle_for_15_minutes_ends_on_the_next_request(): void
    {
        $this->handOver();
        $this->travel(16)->minutes();

        $this->get(route('socio.application', ['token' => $this->token]))->assertRedirect(route('counter.home'));
        $this->assertFalse(CounterHandover::active());
        $this->assertNull(session('counter.handover.draft'));
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'counter.handover.expired')->count());
    }

    public function test_activity_keeps_it_alive_but_two_hours_end_it_anyway(): void
    {
        $this->handOver();
        foreach (range(1, 8) as $i) { // 112 minutes, never idle for 15
            $this->travel(14)->minutes();
            $this->get(route('socio.application', ['token' => $this->token]))->assertOk();
        }
        $this->assertTrue(CounterHandover::active());
        $this->travel(9)->minutes(); // 121 minutes old: past two hours, however active
        $this->get(route('socio.application', ['token' => $this->token]))->assertRedirect(route('counter.home'));
        $this->assertFalse(CounterHandover::active());
    }

    // --- 3–4. Logging in ---------------------------------------------------------------------------------------------------------

    public function test_a_password_login_ends_the_handover_and_never_lands_on_an_application(): void
    {
        $this->handOver();
        auth()->logout();
        session(['url.intended' => route('socio.application', ['token' => $this->token])]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Field by field, as a browser posts them (fillForm() calls a test-only method the confinement rightly refuses).
        $login = Livewire::test(Login::class)->set('data.email', 'ben@club.test')->set('data.password', 'secret-pass')->call('authenticate');

        $this->assertFalse(CounterHandover::active());
        $target = (string) ($login->effects['redirect'] ?? '');
        $this->assertNotSame('', $target);
        $this->assertStringNotContainsString('/solicitud/', $target);
    }

    // --- 5–6. The applicant's own phone; confinement unchanged -----------------------------------------------------------------

    public function test_outside_a_handover_salir_sin_enviar_clears_the_draft_and_the_link_still_works(): void
    {
        MrzPrefill::remember($this->token, ['first_name' => 'ANA']);
        $this->get(route('socio.application', ['token' => $this->token]))->assertOk()->assertSee(__('Salir sin enviar'))
            ->assertDontSee('data-handover-staff-exit', false);

        $left = (string) $this->post(route('socio.application.leave', ['token' => $this->token]))->assertOk()
            ->assertSee(__('No se ha enviado nada.'))->getContent();
        $card = substr($left, (int) strpos($left, 'data-application-left'));
        $this->assertStringNotContainsString('<a ', substr($card, 0, (int) strpos($card, '</div>')), 'the page offers nothing else to tap');
        $this->assertSame([], MrzPrefill::get($this->token));
        $this->assertNull($this->invite->fresh()->submitted_at);

        $this->get(route('socio.application', ['token' => $this->token]))->assertOk()->assertSee('name="first_name"', false);
    }

    public function test_the_confinement_still_holds_for_a_session_in_handover_without_a_pin(): void
    {
        $this->handOver();
        $form = route('socio.application', ['token' => $this->token]);
        foreach (['/members', '/batches', '/'] as $path) {
            $this->get($path)->assertRedirect($form);
        }
        $this->post(route('socio.application.leave', ['token' => $this->token]))->assertRedirect($form); // no leaving on your own
        $this->assertTrue(CounterHandover::active());
    }

    // --- 7. Date of birth ----------------------------------------------------------------------------------------------------------

    public function test_the_date_of_birth_renders_empty_with_a_visible_format_hint(): void
    {
        $html = (string) $this->get(route('socio.application', ['token' => $this->token]))->getContent();
        $this->assertMatchesRegularExpression('/<input id="date_of_birth"[^>]*value=""/', $html);
        $this->assertStringContainsString(e(__('dd/mm/aaaa')), $html);
        $this->assertStringContainsString('data-empty-date', $html);
    }
}
