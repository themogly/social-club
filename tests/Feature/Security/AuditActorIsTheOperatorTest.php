<?php

namespace Tests\Feature\Security;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Models\AuditLog;
use App\Models\DocumentAccessLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\VaultUrl;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 261 — the audit trail names the person at the PIN, and a member's photo needs one.
 *
 * The install is the runbook's owner-logged tablet. Before: the counter photo route (a plain controller, outside
 * 255's Livewire sweep) replaced a member's identity photo with NOBODY at the PIN, attributed to the tablet; and
 * every audit row wrote `Auth::id()`, so a till the manager closed was "closed by the owner" in the audit trail
 * while the record itself said the manager. Real HTTP requests throughout, as in 254/260.
 */
class AuditActorIsTheOperatorTest extends TestCase
{
    use PostsLivewireOverHttp, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $ownerTablet;

    private User $manager;

    private User $staff;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        Storage::fake('documents');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->ownerTablet = $this->user(Role::OWNER, null);
        $this->manager = $this->user(Role::MANAGER, '2468');
        $this->staff = $this->user(Role::STAFF, '1357');
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'photo_path' => null]);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        // The tablet, signed in once, sede chosen — no PIN yet.
        $this->actingAs($this->ownerTablet);
        $this->post(route('counter.location'), ['location_id' => $this->location->id])->assertRedirect();
        CounterOperator::clear();
    }

    private function user(Role $role, ?string $pin): User
    {
        $user = User::factory()->create(['pin' => $pin === null ? null : Hash::make($pin)]);
        $user->assignRole($role->value);
        $user->locations()->attach($this->location->id);

        return $user;
    }

    private function identify(string $pin): void
    {
        $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'),
            ['operatorPin' => $pin], [['unlockOperator']])->assertOk();
    }

    private function postPhoto(): TestResponse
    {
        return $this->post(route('counter.members.photo', $this->member), [
            'photo' => UploadedFile::fake()->image('face.jpg', 400, 400),
            'source' => 'counter',
        ], ['Accept' => 'application/json']);
    }

    // --- 1. Photo, no operator ---------------------------------------------------------------------------------

    public function test_a_member_photo_cannot_be_replaced_with_nobody_at_the_pin(): void
    {
        $this->postPhoto()->assertForbidden();

        $this->assertNull($this->member->fresh()->photo_path, 'the identity photo was replaced by nobody');
        $this->assertSame(0, AuditLog::query()->where('action', 'member.photo.captured')->count());
    }

    // --- 2. Photo, staff operator on an owner tablet ---------------------------------------------------------------

    public function test_the_photo_is_the_operators_act_and_asked_of_the_operator(): void
    {
        $this->identify('1357'); // staff

        $this->postPhoto()->assertOk();

        $this->assertNotNull($this->member->fresh()->photo_path);
        $audit = AuditLog::query()->where('action', 'member.photo.captured')->sole();
        $this->assertSame($this->staff->id, $audit->actor_id, 'the audit row names the tablet, not the operator');
        $this->assertSame($this->staff->id, $audit->after['captured_by']);
    }

    public function test_the_photo_permission_is_the_operators_not_the_tablets(): void
    {
        // Staff without either counter permission — the OWNER tablet still has both.
        SpatieRole::findByName(Role::STAFF->value)->revokePermissionTo(['checkin.manage', 'pos.use']);
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        CounterOperator::set($this->staff);

        $this->postPhoto()->assertForbidden();
        $this->assertNull($this->member->fresh()->photo_path);
    }

    // --- 3. The audit actor on the counter ------------------------------------------------------------------------

    public function test_a_till_closed_by_the_manager_is_audited_as_the_manager_not_the_tablet(): void
    {
        $this->identify('2468'); // manager on the owner tablet

        // Two round trips, as the browser makes them: open the blind count, then submit it on the snapshot the
        // first response returned (startClose() resets the count field, so they cannot share one request).
        $opened = $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'), calls: [['startClose']])->assertOk();
        $this->livewirePost((string) $opened->json('components.0.snapshot'), ['countInput' => '100'], [['submitCount']])->assertOk();

        $this->assertSame(TillSessionStatus::CLOSED, TillSession::query()->withoutGlobalScopes()->sole()->status);
        $audit = AuditLog::query()->where('action', 'till.closed')->sole();
        $this->assertSame($this->manager->id, $audit->actor_id, 'the audit trail names the tablet for the manager\'s close');
    }

    public function test_the_counter_photo_view_is_logged_against_the_operator(): void
    {
        $this->identify('1357');
        $this->postPhoto()->assertOk();

        $url = VaultUrl::photo($this->member->fresh(), $this->ownerTablet, CounterOperator::id());
        $this->get($url)->assertOk();
        $this->assertSame($this->staff->id, DocumentAccessLog::query()->latest('viewed_at')->firstOrFail()->actor_id);

        // A stale `op` (the operator has since signed out) is never honoured — the view falls back to the login.
        CounterOperator::clear();
        $this->get($url)->assertOk();
        $this->assertSame($this->ownerTablet->id, DocumentAccessLog::query()->orderByDesc('id')->firstOrFail()->actor_id);
    }

    // --- 4. No bleed into the panel -------------------------------------------------------------------------------

    public function test_a_panel_action_after_a_counter_pin_is_audited_as_the_panel_user(): void
    {
        $this->identify('1357'); // a staff PIN is now in the SESSION
        $this->assertSame($this->staff->id, CounterOperator::id());

        Filament::setCurrentPanel('admin');
        Livewire::actingAs($this->ownerTablet)->test(ViewMember::class, ['record' => $this->member->getRouteKey()])
            ->callAction('setLimits', data: ['daily_limit_g' => '5', 'monthly_limit_g' => '60', 'reason' => 'Revisión'])
            ->assertHasNoActionErrors();

        $audit = AuditLog::query()->where('action', 'member.limits.set')->sole();
        $this->assertSame($this->ownerTablet->id, $audit->actor_id, 'a PANEL action was attributed to the counter\'s staff operator');
    }

    // --- 5. The pre-operator routes still work --------------------------------------------------------------------

    public function test_choosing_the_sede_and_the_panic_button_work_with_no_pin(): void
    {
        $this->assertNull(CounterOperator::id());

        $this->post(route('counter.location'), ['location_id' => $this->location->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->location->id, session('counter.location_id'));

        $this->post(route('counter.panic'))->assertRedirect();
        $this->assertTrue(OrganisationLockdown::query()->withoutGlobalScopes()->where('organisation_id', $this->org->id)->exists());
    }
}
