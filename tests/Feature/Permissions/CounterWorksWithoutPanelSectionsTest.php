<?php

namespace Tests\Feature\Permissions;

use App\Actions\Members\IssueDocumentUrl;
use App\Actions\Members\IssueMemberToken;
use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\MembershipCounter;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MemberDocument;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\DocumentVault;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 309 — the pins on the other side: the panel sections moved behind `panel.*`, and the COUNTER must not notice.
 * A STAFF operator with no `panel.*` permission at all looks a member up, opens that member's document, opens and closes
 * a till, trips the panic button and approves an application — every counter check is the action permission itself.
 */
class CounterWorksWithoutPanelSectionsTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        $this->staff = User::factory()->create();
        $this->staff->assignRole(Role::STAFF->value);
        $this->staff->locations()->sync([$this->location->id]);
        $this->actingAs($this->staff);
        CounterOperator::set($this->staff);

        $this->assertSame([], array_values(array_filter($this->staff->getAllPermissions()->pluck('name')->all(), fn (string $p): bool => str_starts_with($p, 'panel.'))));
    }

    private function member(): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'first_name' => 'Buscada']);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE]);

        return $member;
    }

    public function test_staff_look_a_member_up_at_the_door(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $member = $this->member();

        Livewire::test(CheckInScreen::class)
            ->set('lookup', (new IssueMemberToken)->handle($member))->call('submitLookup')
            ->assertSet('memberId', $member->id);
    }

    public function test_staff_open_one_members_document(): void
    {
        Storage::fake('documents');
        $member = $this->member();
        DocumentVault::put('members/'.$member->id.'/dni.pdf', 'PDFDATA');
        $document = MemberDocument::factory()->create(['member_id' => $member->id, 'path' => 'members/'.$member->id.'/dni.pdf']);

        $url = (new IssueDocumentUrl)->handle($document, $this->staff);
        $this->withSession(['scope.organisation_id' => $this->org->id])->get($url)->assertOk();
    }

    public function test_staff_open_and_close_a_till(): void
    {
        // Closing is a counter permission a club gives its staff; the panel's till HISTORY stays off.
        $this->setRolePermission(Role::STAFF, 'till.close', true);
        $this->setRolePermission(Role::STAFF, 'stock.take', true);
        CounterOperator::set($this->staff->fresh());

        Livewire::test(TillSession::class)->set('terminal', 'POS-1')->set('floatInput', '100')->call('open');
        $session = TillSessionModel::query()->withoutGlobalScopes()->sole();
        $this->assertSame(TillSessionStatus::OPEN, $session->status);

        Livewire::test(TillSession::class)->call('startClose')->set('countInput', '100')->call('submitCount')->assertSet('countSubmitted', true);
        $this->assertSame(TillSessionStatus::CLOSED, $session->fresh()->status);
    }

    public function test_staff_trip_the_panic_button(): void
    {
        $this->post(route('counter.panic'));

        $this->assertNotNull(OrganisationLockdown::active($this->org->id));
    }

    public function test_staff_open_an_application_for_review(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $application = MemberApplication::factory()->submitted()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'payload' => ['first_name' => 'Solicitante', 'last_name' => 'Nueva', 'document_number' => '11223344X'],
        ]);

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('reviewAltaApplication', $application->id)
            ->assertSet('altaApplicationId', $application->id)
            ->assertSee('Solicitante'); // the approval itself, as STAFF: CounterAltaWizardTest (no panel.* either)
    }
}
