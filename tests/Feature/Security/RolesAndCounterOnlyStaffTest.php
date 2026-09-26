<?php

namespace Tests\Feature\Security;

use App\Actions\Roles\SetRolePermission;
use App\Enums\Role;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\RolesPermissions;
use App\Livewire\Counter\MembershipCounter;
use App\Models\AuditLog;
use App\Models\DocumentAccessLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\Organisation;
use App\Models\RolePermissionOverride;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\DocumentVault;
use App\Support\PermissionDrift;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

/**
 * Prompt 262 — the owner decides what each role can do; staff can see ID scans; counter-only staff land on the counter.
 */
class RolesAndCounterOnlyStaffTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    private User $manager;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        Filament::setCurrentPanel('admin');

        $this->owner = $this->user(Role::OWNER, 'owner@club.test');
        $this->manager = $this->user(Role::MANAGER, 'manager@club.test');
        $this->staff = $this->user(Role::STAFF, 'staff@club.test');
    }

    private function user(Role $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('secret-pass'), 'active' => true]);
        $user->assignRole($role->value);
        $user->locations()->attach($this->location->id);

        return $user;
    }

    private function memberWithScan(): array
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $path = 'documents/'.$member->id.'/dni.png';
        DocumentVault::put($path, 'scan-bytes');
        $document = MemberDocument::factory()->create(['member_id' => $member->id, 'path' => $path]);

        return [$member, $document];
    }

    // --- A. ID scans from the counter ------------------------------------------------------------------------

    public function test_staff_can_open_an_id_scan_from_the_counter_and_the_view_names_them(): void
    {
        [$member, $document] = $this->memberWithScan();
        $this->actingAs($this->staff);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($this->staff);

        $url = Livewire::test(MembershipCounter::class)
            ->call('selectMember', $member->id)
            ->assertSeeHtml('data-view-document')
            ->call('viewDocument', $document->id)
            ->get('documentViewUrl');

        $this->assertNotNull($url, 'staff could not open the scan');
        $this->get($url)->assertOk();
        $this->assertSame($this->staff->id, DocumentAccessLog::query()->sole()->actor_id);
    }

    public function test_with_nobody_at_the_pin_no_scan_opens(): void
    {
        [$member, $document] = $this->memberWithScan();
        $this->actingAs($this->owner); // an owner-logged tablet — still nothing without a PIN
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::clear();

        Livewire::test(MembershipCounter::class)
            ->set('feeMemberId', $member->id)
            ->call('viewDocument', $document->id)
            ->assertSet('documentViewUrl', null)
            ->assertSet('operatorPanelOpen', true);
    }

    // --- B. The roles page ------------------------------------------------------------------------------------

    public function test_the_roles_page_is_owner_only_by_role_and_no_permission_opens_it(): void
    {
        $this->actingAs($this->owner)->get(RolesPermissions::getUrl())->assertOk();
        // A fresh session per person: Filament's AuthenticateSession pins a session to the first user's password
        // hash, so reusing it for another account would log them out (a test artefact, not the gate).
        $this->flushSession();
        $this->actingAs($this->manager)->get(RolesPermissions::getUrl())->assertForbidden();

        // Granting the manager every "system" permission does not open it — it is the ROLE, not a permission.
        foreach (['staff.manage', 'settings.manage', 'audit.view'] as $p) {
            (new SetRolePermission)->handle(Role::MANAGER, $p, true, $this->owner);
        }
        $this->flushSession();
        $this->actingAs($this->manager->fresh())->get(RolesPermissions::getUrl())->assertForbidden();
    }

    public function test_an_override_survives_a_deploy_and_is_not_drift(): void
    {
        (new SetRolePermission)->handle(Role::STAFF, 'membership.fee.waive', false, $this->owner);
        $this->assertFalse($this->staff->fresh()->can('membership.fee.waive'));

        Artisan::call('csc:sync-permissions');

        $this->assertFalse(SpatieRole::findByName(Role::STAFF->value)->hasPermissionTo('membership.fee.waive'), 'the deploy reverted the owner\'s choice');
        $this->assertSame(0, Artisan::call('csc:sync-permissions', ['--check' => true]));
        $this->assertTrue(PermissionDrift::report()['in_sync']);
        $this->assertNotEmpty(PermissionDrift::overrideLines(), 'the health page does not list the club\'s choice');
    }

    public function test_catalogue_changes_still_flow_through_the_sync(): void
    {
        // A permission the code grants but the database lost comes back with its default.
        SpatieRole::findByName(Role::STAFF->value)->revokePermissionTo('pos.use');
        Artisan::call('csc:sync-permissions');
        $this->assertTrue(SpatieRole::findByName(Role::STAFF->value)->hasPermissionTo('pos.use'));

        // A permission that is no longer in the catalogue leaves the role, and its override with it.
        Permission::findOrCreate('legacy.removed', 'web');
        SpatieRole::findByName(Role::STAFF->value)->givePermissionTo('legacy.removed');
        RolePermissionOverride::create(['role' => Role::STAFF->value, 'permission' => 'legacy.removed', 'granted' => true]);

        Artisan::call('csc:sync-permissions');

        $this->assertFalse(SpatieRole::findByName(Role::STAFF->value)->fresh()->hasPermissionTo('legacy.removed'));
        $this->assertSame(0, RolePermissionOverride::query()->where('permission', 'legacy.removed')->count());
    }

    public function test_the_owner_role_is_locked(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(RolesPermissions::class)->call('toggle', Role::OWNER->value, 'audit.view')->assertForbidden();
        $this->assertTrue($this->owner->fresh()->can('audit.view'));

        $this->expectException(AuthorizationException::class);
        (new SetRolePermission)->handle(Role::OWNER, 'audit.view', false, $this->owner);
    }

    public function test_a_manager_cannot_change_a_role_even_by_a_crafted_call(): void
    {
        $this->expectException(AuthorizationException::class);
        (new SetRolePermission)->handle(Role::STAFF, 'wallet.adjust', true, $this->manager);
    }

    public function test_every_change_is_audited_from_and_to(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(RolesPermissions::class)->call('toggle', Role::STAFF->value, 'wallet.adjust');

        $this->assertTrue($this->staff->fresh()->can('wallet.adjust'));
        $audit = AuditLog::query()->where('action', 'role.permission.changed')->sole();
        $this->assertFalse($audit->before['granted']);
        $this->assertTrue($audit->after['granted']);
        $this->assertSame('wallet.adjust', $audit->after['permission']);
        $this->assertSame(Role::STAFF->value, $audit->after['role']);
        $this->assertSame($this->owner->id, $audit->actor_id);

        Livewire::test(RolesPermissions::class)->call('restoreDefaults', Role::STAFF->value);
        $this->assertFalse($this->staff->fresh()->can('wallet.adjust'));
        $this->assertSame(0, RolePermissionOverride::query()->count());
    }

    // --- C. Panel access and the counter-only login -----------------------------------------------------------

    public function test_the_defaults_give_managers_the_panel_and_staff_the_scans_but_not_the_panel(): void
    {
        $this->assertContains('panel.access', Permissions::for(Role::MANAGER));
        $this->assertNotContains('panel.access', Permissions::for(Role::STAFF));
        $this->assertContains('member.documents.view', Permissions::for(Role::STAFF));
        $this->assertContains('member.documents.view', Permissions::for(Role::MANAGER));
    }

    public function test_a_counter_only_account_signs_in_and_lands_on_the_counter(): void
    {
        Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@club.test', 'password' => 'secret-pass'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(route('counter.home'));

        $this->assertAuthenticatedAs($this->staff);
    }

    public function test_an_inactive_account_is_still_refused(): void
    {
        $this->staff->forceFill(['active' => false])->save();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@club.test', 'password' => 'secret-pass'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_a_manager_still_lands_on_the_panel(): void
    {
        Livewire::test(Login::class)
            ->fillForm(['email' => 'manager@club.test', 'password' => 'secret-pass'])
            ->call('authenticate')
            ->assertRedirect(Filament::getUrl());
    }

    public function test_panel_urls_send_a_counter_only_account_to_the_counter(): void
    {
        $this->actingAs($this->staff);

        foreach (['/', '/members', '/users'] as $url) {
            $response = $this->get($url);
            $response->assertRedirect(route('counter.home'));
            $this->assertStringNotContainsString('fi-sidebar', (string) $response->getContent());
        }

        // The counter itself answers.
        session(['counter.location_id' => $this->location->id]);
        $this->get(route('counter.home'))->assertOk();
    }

    public function test_the_counter_admin_link_follows_panel_access(): void
    {
        session(['counter.location_id' => $this->location->id]);

        $this->actingAs($this->staff);
        $this->assertStringNotContainsString('data-counter-admin-link', (string) $this->get(route('counter.home'))->getContent());

        $this->flushSession();
        session(['counter.location_id' => $this->location->id]);
        $this->actingAs($this->manager);
        $this->assertStringContainsString('data-counter-admin-link', (string) $this->get(route('counter.home'))->getContent());
    }
}
