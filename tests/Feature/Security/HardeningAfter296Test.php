<?php

namespace Tests\Feature\Security;

use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Members\SendApplicationInvite;
use App\Actions\Staff\AnnulClockEvent;
use App\Actions\Staff\ClockIn;
use App\Actions\Stock\IntakeBatch;
use App\Actions\UnlockOperator;
use App\Enums\Role;
use App\Enums\StaffClockSource;
use App\Filament\Pages\RegistroJornada;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\WorkedHours;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Post-296 audit, Phase 3 — hardening. Each test names its finding in the report
 * (`audits/reports/2026-09-post-296-security.md`).
 */
class HardeningAfter296Test extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
        Artisan::call('csc:sync-permissions');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'timezone' => 'Europe/Madrid']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = $this->person(Role::OWNER);
        $this->manager = $this->person(Role::MANAGER);
    }

    private function person(Role $role): User
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->sede->id]);

        return $user;
    }

    // --- B·P3-1: the below-cost confirmation prices the batch whose Precio was opened --------------------------------

    public function test_the_below_cost_confirmation_ignores_a_tampered_batch_argument(): void
    {
        $this->actingAs($this->owner);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $opened = (new IntakeBatch)->handle($genetic, $this->sede, ['grams' => '100', 'cost_per_gram_cents' => 950, 'price_per_gram_cents' => 1200]);
        $other = (new IntakeBatch)->handle($genetic, $this->sede, ['grams' => '100', 'cost_per_gram_cents' => 950, 'price_per_gram_cents' => 1200]);

        $list = Livewire::test(ListBatches::class)->callTableAction('price', $opened, ['rate_eur' => '8'])->assertActionMounted('belowCost');
        foreach ((array) $list->get('mountedActions') as $i => $mounted) {
            if (($mounted['name'] ?? null) === 'belowCost') {
                $list->set("mountedActions.{$i}.arguments.batch", $other->id);
            }
        }
        $list->callMountedAction();

        $this->assertSame(1200, $other->fresh()->price_per_gram_cents, 'the tampered argument repriced another batch');
        $this->assertSame(800, $opened->fresh()->price_per_gram_cents);
    }

    // --- B·P3-2: staff hours stay inside the organisation -------------------------------------------------------------

    public function test_an_owner_sees_and_manages_only_their_own_organisations_sedes(): void
    {
        $elsewhere = Location::factory()->create(['organisation_id' => Organisation::factory()->create()->id]);

        $this->assertNotContains($elsewhere->id, WorkedHours::viewableLocationIds($this->owner));
        $this->assertContains($this->sede->id, WorkedHours::viewableLocationIds($this->owner));
        $this->assertFalse(WorkedHours::canManageAt($this->owner, $elsewhere));
        $this->assertTrue(WorkedHours::canManageAt($this->owner, $this->sede));
    }

    // --- B·P3-3: the registro's data is not a wire action ------------------------------------------------------------

    public function test_the_registro_de_jornada_data_methods_are_not_callable_from_the_browser(): void
    {
        foreach (['reportRows', 'peopleOptions', 'sedeOptions', 'canManage'] as $method) {
            $this->assertFalse((new ReflectionMethod(RegistroJornada::class, $method))->isPublic(), "{$method} is a public wire action");
        }

        $this->actingAs($this->owner)->get(RegistroJornada::getUrl())->assertOk(); // the page still renders them
    }

    // --- B·P3-4: nobody corrects their own hours but the owner -------------------------------------------------------

    public function test_a_manager_cannot_add_or_annul_their_own_hours_but_can_a_colleagues(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-26 18:00', 'Europe/Madrid'));
        $colleague = $this->person(Role::STAFF);

        (new ClockIn)->handle($colleague, $this->sede, $this->manager, StaffClockSource::MANAGER_CORRECTION, now()->subHours(3), 'Olvidó fichar');

        try {
            (new ClockIn)->handle($this->manager, $this->sede, $this->manager, StaffClockSource::MANAGER_CORRECTION, now()->subHours(3), 'Me olvidé');
            $this->fail('a manager added their own hours');
        } catch (AuthorizationException) {
        }

        $own = (new ClockIn)->handle($this->manager, $this->sede, $this->manager, StaffClockSource::PIN);
        try {
            (new AnnulClockEvent)->handle($own, $this->manager, 'Error');
            $this->fail('a manager annulled their own hours');
        } catch (AuthorizationException) {
        }

        $ownersOwn = (new ClockIn)->handle($this->owner, $this->sede, $this->owner, StaffClockSource::PIN);
        $this->assertNotNull((new AnnulClockEvent)->handle($ownersOwn, $this->owner, 'Error'), 'the owner answers to nobody here');
    }

    // --- A·6: the PIN throttle holds under parallel requests ----------------------------------------------------------

    public function test_a_correct_pin_is_refused_while_every_attempt_is_already_taken(): void
    {
        $this->owner->forceFill(['pin' => Hash::make('4321')])->save();
        $unlock = new UnlockOperator;
        $max = $unlock->maxAttemptsAt($this->sede);

        // As if `max` wrong guesses were in flight at once: each reserved its attempt before any finished.
        Cache::store(config('cache.limiter'))->put('pin:probe:attempts', $max, 300); // the PIN tally's store (344)

        $this->assertNull($unlock->handle($this->sede, '4321', 'pin:probe'), 'a guess got past a full set of attempts');
        $this->assertTrue($unlock->isLockedOut('pin:probe'));
    }

    public function test_a_correct_pin_gives_its_attempt_back(): void
    {
        $this->owner->forceFill(['pin' => Hash::make('4321')])->save();
        $unlock = new UnlockOperator;

        $this->assertNotNull($unlock->handle($this->sede, '4321', 'pin:ok'));
        $this->assertSame($unlock->maxAttemptsAt($this->sede), $unlock->attemptsRemaining($this->sede, 'pin:ok'));
        $this->assertNull($unlock->handle($this->sede, '0000', 'pin:ok'));
        $this->assertSame($unlock->maxAttemptsAt($this->sede) - 1, $unlock->attemptsRemaining($this->sede, 'pin:ok'));
    }

    // --- A·7: a CSP report starts no session ----------------------------------------------------------------------------

    public function test_a_csp_report_starts_no_session(): void
    {
        $response = $this->postJson('/csp-report', ['csp-report' => ['document-uri' => 'https://club.test/x', 'violated-directive' => 'img-src']]);

        $this->assertLessThan(300, $response->getStatusCode());
        $response->assertCookieMissing((string) config('session.cookie'));
    }

    // --- A·8: invitations are rate-limited -------------------------------------------------------------------------------

    public function test_an_invitation_cannot_be_resent_straight_away_or_more_than_five_times_a_day(): void
    {
        Mail::fake();
        $this->actingAs($this->manager);
        $application = (new IssueApplicationInvite)->handle($this->manager, $this->sede->id, 'nueva@example.test', null);
        $sender = new SendApplicationInvite;

        $this->assertTrue($sender->handle($application));
        $this->assertFalse($sender->handle($application), 'resent within the cooldown');
        $this->assertNotNull($sender->refusal);

        $sent = 1;
        foreach (range(1, 8) as $i) {
            $this->travel(11)->minutes();
            $sent += (new SendApplicationInvite)->handle($application) ? 1 : 0;
        }
        $this->assertSame(5, $sent, 'more than five a day');
    }

    public function test_one_person_cannot_send_more_than_twenty_invitations_an_hour(): void
    {
        Mail::fake();
        $this->actingAs($this->manager);

        $sent = 0;
        foreach (range(1, 25) as $i) {
            $application = (new IssueApplicationInvite)->handle($this->manager, $this->sede->id, "persona{$i}@example.test", null);
            $sent += (new SendApplicationInvite)->handle($application) ? 1 : 0;
        }

        $this->assertSame(20, $sent);
    }
}
