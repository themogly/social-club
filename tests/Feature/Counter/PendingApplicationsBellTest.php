<?php

namespace Tests\Feature\Counter;

use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\PendingApplicationsBell;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterBasket;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 330 — Ben: "A notification at the top somewhere in the till so it's really obvious… they want it just to appear
 * and they can approve." A small component of its own in the counter's top bar polls this sede's applications awaiting
 * review: a bell with the count, and a banner under the bar when a new one arrives ("Thomas P."), whose button opens the
 * one review on Socios (329's ?solicitud=). A poll never re-renders the screen underneath.
 */
class PendingApplicationsBellTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $dreamGreen;

    private Location $greenhouse;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->dreamGreen = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Dream Green']);
        $this->greenhouse = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'DG Greenhouse']);
        app(ActiveScope::class)->setLocation($this->dreamGreen->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->dreamGreen->id, $this->greenhouse->id]);
        $this->actingAs($owner);
        $this->staff = User::factory()->create();
        $this->staff->assignRole(Role::STAFF->value);
        $this->staff->locations()->sync([$this->dreamGreen->id]);
        CounterOperator::set($this->staff);
        session(['counter.location_id' => $this->dreamGreen->id]);
    }

    private function apply(string $first = 'Thomas', string $last = 'Powney', ?Location $at = null): MemberApplication
    {
        return MemberApplication::factory()->create(['organisation_id' => $this->org->id, 'location_id' => ($at ?? $this->dreamGreen)->id,
            'status' => ApplicationStatus::PENDING, 'submitted_at' => now()->subMinute(), 'payload' => [
                'first_name' => $first, 'last_name' => $last, 'email' => 'thomas.powney@example.test', 'phone' => '611222333',
                'document_type' => 'DNI', 'document_number' => 'PRUEBA-330',
            ]]);
    }

    private function bell(): Testable
    {
        return Livewire::test(PendingApplicationsBell::class);
    }

    private static function kb(Testable $testable): float
    {
        return round(strlen((string) json_encode(['components' => [['snapshot' => json_encode($testable->snapshot), 'effects' => $testable->effects]], 'assets' => []])) / 1024, 1);
    }

    // --- 1–2. The badge, and who sees it ----------------------------------------------------------------------------------------

    public function test_the_bell_counts_this_sedes_pending_applications(): void
    {
        $this->bell()->assertDontSeeHtml('data-bell-count');

        $this->apply('Marta', 'Otrasede', $this->greenhouse);
        $this->bell()->assertDontSeeHtml('data-bell-count');

        $this->apply();
        $this->bell()->assertSeeHtml('data-bell-count')->assertSeeHtml('>1<')
            ->assertSeeHtml('aria-label="'.trans_choice(':count solicitud pendiente|:count solicitudes pendientes', 1, ['count' => 1]).'"');
    }

    /** Found in the browser: a PIN unlock mounts the bell inside a Livewire update, before any screen applied its sede. */
    public function test_the_bell_reads_the_counters_own_sede_when_mounted_mid_update(): void
    {
        $this->apply();
        app(ActiveScope::class)->useLocation(null);

        $this->bell()->assertSeeHtml('data-bell-count')->assertSet('locationId', $this->dreamGreen->id);
    }

    public function test_nothing_renders_without_an_operator_or_during_a_handover(): void
    {
        $this->apply();

        CounterOperator::clear();
        $this->bell()->assertDontSeeHtml('data-pending-applications-bell')->assertDontSee('Thomas');

        CounterOperator::set($this->staff);
        CounterHandover::begin($this->staff->id, $this->dreamGreen->id);
        $this->bell()->assertDontSeeHtml('data-pending-applications-bell')->assertDontSee('Thomas');
    }

    // --- 3–4. A new one arrives, once; the poll is small ----------------------------------------------------------------------------

    public function test_a_new_application_appears_within_a_poll_and_is_announced_once(): void
    {
        $bell = $this->bell()->assertDontSeeHtml('data-applications-banner');

        $this->apply();
        $bell->call('check')
            ->assertSeeHtml('data-applications-banner')->assertSee(__('Nueva solicitud: :name', ['name' => 'Thomas P.']))
            ->assertDontSee('Powney')->assertDontSee('thomas.powney@example.test')->assertDontSee('611222333')->assertDontSee('PRUEBA-330')
            ->assertDispatched('applications-arrived');

        $bell->call('check')->assertNotDispatched('applications-arrived');
        $this->assertLessThan(5, self::kb($bell), 'the bell\'s poll is not small');
    }

    public function test_the_bell_is_its_own_component_in_the_top_bar_not_part_of_the_screen(): void
    {
        $bar = (string) file_get_contents(resource_path('views/components/counter/top-bar.blade.php'));
        $this->assertStringContainsString('<livewire:counter.pending-applications-bell', $bar);
        $this->assertStringNotContainsString('pending-applications-bell', (string) file_get_contents(resource_path('views/livewire/counter/dispensary-pos.blade.php')));
        $bell = (string) file_get_contents(resource_path('views/livewire/counter/pending-applications-bell.blade.php'));
        $this->assertStringContainsString('wire:poll.15s', $bell);
        // The banner lands in the flow under the bar (a static slot in the layout), so it covers nothing on the screen.
        $this->assertStringContainsString("@teleport('#counter-notices')", $bell);
        $this->assertStringContainsString('<div id="counter-notices" role="status"></div>', (string) file_get_contents(resource_path('views/components/layouts/counter.blade.php')));
    }

    // --- 5–7. Acting on it ------------------------------------------------------------------------------------------------------------

    public function test_revisar_opens_socios_on_that_application_and_asks_first_with_a_basket(): void
    {
        $bell = $this->bell();
        $application = $this->apply();
        $bell->call('check')->call('review', $application->id)->assertRedirect(route('counter.members', ['solicitud' => $application->id]));

        CounterBasket::put('pos', $this->dreamGreen->id, [['genetic_id' => 'x', 'grams_cg' => 100]]);
        $bell = $this->bell();
        $bell->call('review', $application->id)->assertNoRedirect()->assertSet('confirmingReviewOf', $application->id)
            ->assertSee(__('Tienes una cesta a medias: se guarda y la encontrarás al volver.'));
        $bell->call('confirmReview')->assertRedirect(route('counter.members', ['solicitud' => $application->id]));
        $this->assertNotSame([], CounterBasket::get('pos', $this->dreamGreen->id), 'the basket was lost');
    }

    public function test_luego_hides_the_banner_for_the_session_and_the_badge_stays(): void
    {
        $bell = $this->bell();
        $this->apply();
        $bell->call('check')->assertSeeHtml('data-applications-banner');

        $bell->call('later')->assertDontSeeHtml('data-applications-banner')->assertSeeHtml('data-bell-count');
        $this->bell()->assertDontSeeHtml('data-applications-banner')->assertSeeHtml('data-bell-count'); // another screen, same session
    }

    public function test_without_the_review_permission_the_button_says_tell_a_manager_and_review_is_refused(): void
    {
        $this->setRolePermission(Role::STAFF, 'applications.review', false);
        CounterOperator::set($this->staff->fresh());
        $bell = $this->bell();
        $application = $this->apply();

        $bell->call('check')->assertSee(__('Avisa a un responsable'))->assertDontSee(__('Revisar y aprobar'));
        $bell->call('review', $application->id)->assertNoRedirect();
    }

    // --- 8. The chime -------------------------------------------------------------------------------------------------------------------

    public function test_the_chime_is_off_by_default_and_plays_only_when_on(): void
    {
        $bell = $this->bell();
        $this->apply();
        $bell->call('check')->assertDispatched('applications-arrived', chime: false);

        Settings::set('applications_chime_enabled', true, SettingType::BOOL, $this->dreamGreen->id);
        $bell = $this->bell();
        $this->apply('Lucía', 'Otra');
        $bell->call('check')->assertDispatched('applications-arrived', chime: true);
    }
}
