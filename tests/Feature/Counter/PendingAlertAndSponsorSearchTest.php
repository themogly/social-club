<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\AvaladorResolver;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 264 — two sign-up fixes from the tester's tablet report.
 *
 * 1. "1 solicitud pendiente" on the hub opened the Alta modal on the SIGN-UP chooser ("¿Cómo vais a rellenar la
 *    solicitud?"), with the pending application a small row at the bottom — it read as "sign up a new member".
 *    One pending → that application's review; several → the pending list first.
 * 2. The admin member form's avalador Select listed and searched member NUMBERS only: searching the sponsor's
 *    surname returned nothing. It now searches name or number and labels "Nombre Apellidos · M-00028".
 */
class PendingAlertAndSponsorSearchTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        $this->operator = User::factory()->create();
        $this->operator->assignRole(Role::STAFF->value); // staff hold applications.review (174)
        $this->operator->locations()->sync([$this->location->id]);
        $this->actingAs($this->operator);
        CounterOperator::set($this->operator);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    private function pending(string $first, string $last, ?Organisation $org = null, ?Location $location = null): MemberApplication
    {
        return MemberApplication::factory()->submitted()->create([
            'organisation_id' => ($org ?? $this->org)->id,
            'location_id' => ($location ?? $this->location)->id,
            'payload' => ['first_name' => $first, 'last_name' => $last, 'document_number' => '99887766Q'],
        ]);
    }

    private function fromTheAlert(): Testable
    {
        return Livewire::withQueryParams(['alert' => 'pending_applications'])->test(MembershipCounter::class);
    }

    // --- 1. The pending-application alert -------------------------------------------------------------------

    public function test_one_pending_application_opens_its_review(): void
    {
        $application = $this->pending('Zoe', 'Pendiente');

        $this->fromTheAlert()
            ->assertSet('altaOpen', true)
            ->assertSet('altaApplicationId', $application->id)
            ->assertSeeHtml('data-alta-review')
            ->assertSee('Zoe Pendiente')
            ->assertDontSeeHtml('data-alta-staff-form'); // not the sign-up chooser
    }

    public function test_several_pending_applications_open_the_list_first(): void
    {
        $this->pending('Zoe', 'Pendiente');
        $this->pending('Ana', 'Segunda');

        $html = $this->fromTheAlert()
            ->assertSet('altaOpen', true)
            ->assertSet('altaApplicationId', null)
            ->html();

        $list = strpos($html, 'data-alta-pending');
        $chooser = strpos($html, 'data-alta-staff-form');
        $this->assertNotFalse($list, 'the pending list is not shown');
        $this->assertTrue($chooser === false || $list < $chooser, 'the sign-up chooser comes before the pending list');
    }

    public function test_another_organisations_application_is_never_opened(): void
    {
        $otherOrg = Organisation::factory()->create();
        $otherLocation = Location::factory()->create(['organisation_id' => $otherOrg->id]);
        $this->pending('Foreign', 'Applicant', $otherOrg, $otherLocation);

        $this->fromTheAlert()
            ->assertSet('altaApplicationId', null)
            ->assertDontSee('Foreign Applicant');
    }

    public function test_without_applications_review_the_alert_opens_no_one(): void
    {
        $this->pending('Zoe', 'Pendiente');
        $this->setRolePermission(Role::STAFF, 'applications.review', false);

        $this->fromTheAlert()
            ->assertSet('altaApplicationId', null)
            ->assertDontSee('Zoe Pendiente')
            ->assertDontSee('99887766Q');
    }

    // --- 2. The sponsor, by name, in the admin member form ----------------------------------------------------

    private function sponsor(string $first, string $last, string $no, MemberStatus $status = MemberStatus::ACTIVE, ?Organisation $org = null): Member
    {
        return Member::factory()->create([
            'organisation_id' => ($org ?? $this->org)->id, 'first_name' => $first, 'last_name' => $last,
            'member_no' => $no, 'status' => $status,
        ]);
    }

    public function test_the_admin_form_finds_a_sponsor_by_surname_and_labels_them_by_name_and_number(): void
    {
        $alba = $this->sponsor('Lucía', 'Alba', 'M-00028');
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        Filament::setCurrentPanel('admin');

        $select = Livewire::actingAs($owner)->test(CreateMember::class)
            ->instance()->form->getComponent('avalador_member_id');

        $bySurname = $select->getSearchResults('alba');
        $this->assertSame([$alba->id => 'Lucía Alba · M-00028'], $bySurname, 'searching the surname found nobody');
        $this->assertSame([$alba->id => 'Lucía Alba · M-00028'], $select->getSearchResults('M-00028'), 'the number no longer works');
    }

    public function test_only_active_members_of_this_organisation_are_offered(): void
    {
        $this->sponsor('Ana', 'Alba', 'M-00001');
        $this->sponsor('Beto', 'Alba', 'M-00002', MemberStatus::SUSPENDED);
        $this->sponsor('Carla', 'Alba', 'M-00003', MemberStatus::ACTIVE, Organisation::factory()->create());

        $labels = array_values(AvaladorResolver::candidates($this->org->id, 'alba')->map(fn (Member $m) => AvaladorResolver::label($m))->all());

        $this->assertSame(['Ana Alba · M-00001'], $labels);
    }
}
