<?php

namespace Tests\Feature\Security;

use App\Actions\Till\OpenTill;
use App\Actions\UnlockOperator;
use App\Actions\Users\EnsureRoleChangeIsAllowed;
use App\Enums\Role;
use App\Filament\Pages\RolesPermissions;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Livewire\Counter\MembershipCounter;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\PanelIdentity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\ChangesRolePermissions;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 270 — the pre-live security audit's Phase 1 (and its Phase 2/3), each proven over the request that exploited it.
 *
 * 267 made a PIN a full sign-in; the code around the PIN was built when a PIN only named who did a transaction. The audit
 * found a second door that switched the person without the login (the till handover), a throttle the attacker's own PIN
 * reset, shared PINs signing in as whoever matched first, `staff.manage` making its holder an owner, and a locked counter
 * still answering panel Livewire calls. Plus: the sponsor lookup leaking member rows with nobody at the PIN, managers
 * editing sedes they do not work at, ID scans cacheable on shared tablets, deactivated accounts keeping the counter,
 * and a CSP with nowhere to report.
 */
class PreLiveSignInHardeningTest extends TestCase
{
    use ChangesRolePermissions, PostsLivewireOverHttp, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    private User $manager;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->owner = $this->user(Role::OWNER, '1111');
        $this->manager = $this->user(Role::MANAGER, '2222');
        $this->staff = $this->user(Role::STAFF, '3333');
        (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $this->owner->id]);
    }

    private function user(Role $role, ?string $pin): User
    {
        $user = User::factory()->create(['pin' => $pin === null ? null : Hash::make($pin), 'active' => true]);
        $user->assignRole($role->value);
        $user->locations()->attach($this->location->id);

        return $user;
    }

    private function tablet(User $device): void
    {
        $this->actingAs($device);
        $this->post(route('counter.location'), ['location_id' => $this->location->id]);
        CounterOperator::clear();
    }

    /** The PIN, then the once-a-shift password (post-296 fix 3) — these tests are about who the panel belongs to. */
    private function pin(string $pin): TestResponse
    {
        $response = $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'),
            ['operatorPin' => $pin], [['unlockOperator']]);
        if (Auth::user() instanceof User) {
            PanelIdentity::confirmed(Auth::user());
        }

        return $response;
    }

    // --- A1. The till handover is a sign-in ------------------------------------------------------------------------

    public function test_a_till_handover_signs_the_incoming_person_in(): void
    {
        $this->tablet($this->owner);
        $this->pin('1111')->assertOk();
        $this->assertAuthenticatedAs($this->owner);

        $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'), [
            'handoverOpen' => true, 'handoverCounted' => '100', 'handoverPin' => '3333',
        ], [['handOver']])->assertOk();

        $this->assertSame($this->staff->id, CounterOperator::id());
        $this->assertAuthenticatedAs($this->staff);
        $this->get('/users')->assertRedirect(route('counter.home')); // not the owner's panel any more
    }

    public function test_only_the_sign_in_action_names_the_counter_operator(): void
    {
        $callers = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'CounterOperator::set(')) {
                $callers[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame(['Actions/Counter/SignInOperator.php'], $callers, 'a path names the operator without signing in');
    }

    // --- A2. A correct PIN does not reset the lockout --------------------------------------------------------------

    public function test_the_attackers_own_pin_does_not_reset_the_lockout(): void
    {
        $unlock = new UnlockOperator;
        $key = 'probe:'.$this->location->id;

        foreach (range(1, 4) as $i) {
            $this->assertNull($unlock->handle($this->location, '0000', $key));
        }
        $this->assertTrue($unlock->handle($this->location, '3333', $key)?->is($this->staff)); // their own PIN still works…

        $this->assertNull($unlock->handle($this->location, '9999', $key)); // …but the fifth wrong guess locks the pad
        $this->assertTrue($unlock->isLockedOut($key));
        $this->assertNull($unlock->handle($this->location, '1111', $key), 'the owner\'s PIN opened a locked pad');
    }

    // --- A3. A PIN names exactly one person ------------------------------------------------------------------------

    public function test_a_pin_shared_by_two_people_signs_in_neither_and_is_audited(): void
    {
        $this->staff->forceFill(['pin' => Hash::make('1111')])->save(); // the owner's PIN, set before 270's rule

        $this->assertNull((new UnlockOperator)->handle($this->location, '1111', 'probe:shared'));

        $audit = AuditLog::query()->where('action', 'counter.pin.ambiguous')->sole();
        $this->assertEqualsCanonicalizing([$this->owner->id, $this->staff->id], $audit->after['user_ids']);
    }

    public function test_the_personal_form_refuses_a_pin_someone_else_already_uses(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nuevo', 'email' => 'nuevo@club.test', 'password' => 'password-1234',
                'pin' => '3333', 'roles' => [SpatieRole::findByName(Role::STAFF->value)->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['pin']);

        Livewire::test(EditUser::class, ['record' => $this->staff->getRouteKey()])
            ->fillForm(['set_pin' => true, 'pin' => '2222'])
            ->call('save')
            ->assertHasFormErrors(['pin']);

        $this->assertFalse(User::pinIsTaken('3333', $this->staff->id), 'your own PIN is not "taken"');
    }

    // --- A4. Only an owner makes an owner --------------------------------------------------------------------------

    public function test_a_manager_holding_staff_manage_cannot_promote_themselves_or_touch_the_owner(): void
    {
        $this->setRolePermission(Role::MANAGER, 'staff.manage', true);
        $this->actingAs($this->manager);
        $ownerRole = SpatieRole::findByName(Role::OWNER->value)->id;

        $this->assertFalse($this->manager->can('update', $this->owner), 'a manager may edit the owner\'s row');
        $this->assertSame(403, $this->get(EditUser::getUrl(['record' => $this->owner->getRouteKey()]))->status(), 'the owner\'s edit page opened');

        // Their own row: the roles select is locked, and a forged submission does not stick.
        Livewire::test(EditUser::class, ['record' => $this->manager->getRouteKey()])
            ->assertFormFieldIsDisabled('roles')
            ->set('data.roles', [(string) $ownerRole])
            ->call('save');
        $this->assertFalse($this->manager->fresh()->hasRole(Role::OWNER->value), 'a manager promoted themselves');

        // The owner role is not even offered, and a forged create is refused.
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'X', 'email' => 'x@club.test', 'password' => 'password-1234', 'pin' => '7777', 'roles' => [(string) $ownerRole]])
            ->call('create');
        $this->assertFalse(User::query()->where('email', 'x@club.test')->exists(), 'a manager created an owner');

        // The server-side rule itself, whatever a form submits.
        $guard = new EnsureRoleChangeIsAllowed;
        foreach ([[$this->manager, [(string) $ownerRole]], [null, [(string) $ownerRole]], [$this->owner, [(string) SpatieRole::findByName(Role::STAFF->value)->id]]] as [$target, $roles]) {
            try {
                $guard->handle($this->manager, $target, $roles);
                $this->fail('the role change was allowed');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $guard->handle($this->owner, $this->manager, [(string) $ownerRole]); // an owner may

        // A staff member's row is still theirs to manage.
        $this->assertTrue($this->manager->can('update', $this->staff));
    }

    // --- A5. Locked means locked, for Livewire too -----------------------------------------------------------------

    public function test_a_locked_counter_refuses_a_replayed_panel_livewire_call(): void
    {
        $this->tablet($this->owner);
        $this->pin('1111')->assertOk();
        $snapshot = $this->snapshotFrom(RolesPermissions::getUrl(), 'App\\Filament\\Pages\\RolesPermissions');

        // The idle lock.
        $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'), calls: [['__dispatch', ['counter-lock', []]]])->assertOk();
        $this->assertNull(CounterOperator::id());

        $this->livewirePost($snapshot, calls: [['toggle', ['STAFF', 'staff.manage']]])->assertForbidden();
        $this->assertFalse(SpatieRole::findByName(Role::STAFF->value)->hasPermissionTo('staff.manage'));
        $this->assertTrue(AuditLog::query()->where('action', 'counter.lock.refused_call')->exists());

        // The PIN reopens it.
        $this->pin('1111')->assertOk();
        $this->livewirePost($this->snapshotFrom(RolesPermissions::getUrl(), 'App\\Filament\\Pages\\RolesPermissions'))->assertOk();
    }

    // --- A6. The sponsor lookup answers nothing with nobody at the PIN ---------------------------------------------

    public function test_the_sponsor_lookup_returns_no_member_with_nobody_identified(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'member_no' => 'M-77777']);
        $this->actingAs($this->owner);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($this->owner);

        $screen = Livewire::test(MembershipCounter::class)->set('altaForm.avalador_ref', 'M-77777');
        $this->assertTrue($screen->instance()->avaladorFeedback()['member']?->is($member));

        CounterOperator::clear();
        $this->assertSame(['status' => 'empty', 'member' => null], $screen->instance()->avaladorFeedback());
    }

    // --- A7. A sede is managed by those who work there -------------------------------------------------------------

    public function test_a_manager_cannot_edit_a_sede_they_do_not_work_at_or_create_one(): void
    {
        $elsewhere = Location::factory()->create(['organisation_id' => $this->org->id]);

        $this->assertTrue($this->manager->can('update', $this->location));
        $this->assertFalse($this->manager->can('update', $elsewhere));
        $this->assertFalse($this->manager->can('view', $elsewhere));
        $this->assertFalse($this->manager->can('create', Location::class));

        $this->actingAs($this->manager);
        $this->get(LocationResource::getUrl('edit', ['record' => $elsewhere]))->assertNotFound(); // not even in their list

        $this->assertTrue($this->owner->can('update', $elsewhere));
        $this->assertTrue($this->owner->can('create', Location::class));
    }

    // --- Phase 3: a deactivated account loses the counter on its next request --------------------------------------

    public function test_a_deactivated_account_is_signed_out_of_the_counter_on_its_next_request(): void
    {
        $this->tablet($this->owner);
        $this->pin('2222')->assertOk();
        $this->get('/counter/till')->assertOk();

        $this->manager->forceFill(['active' => false])->save();
        app('auth')->forgetGuards(); // a real next request loads the user afresh; the test app keeps the guard in memory

        $this->get('/counter/till')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNull(CounterOperator::id());
    }

    // --- Phase 3: the CSP has somewhere to report ------------------------------------------------------------------

    public function test_csp_reports_are_accepted_and_logged_without_query_strings(): void
    {
        $this->assertStringContainsString('report-uri /csp-report', (string) $this->get('/login')->headers->get('Content-Security-Policy-Report-Only'));

        Log::spy();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => [
            'document-uri' => 'https://club.test/members/documents/01ABC?signature=secret&expires=1',
            'violated-directive' => 'script-src',
            'blocked-uri' => 'https://evil.test/x.js?token=abc',
        ]]))->assertNoContent();

        Log::shouldHaveReceived('warning')->with('csp.violation', [
            'page' => 'https://club.test/members/documents/01ABC',
            'directive' => 'script-src',
            'blocked' => 'https://evil.test/x.js',
        ])->once();
    }
}
