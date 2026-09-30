<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\ApplicationStatus;
use App\Enums\DashboardAlert;
use App\Enums\Role;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 329 — Ben: staff couldn't find people who had signed up. Thomas Powney submitted his application; the counter's
 * search said "Sin resultados" because it searched members only. It now finds this sede's applications awaiting
 * review too, under *Solicitudes pendientes*, and a tap opens the ONE existing review flow on Socios (approve → the new
 * member with the fee panel). Name, date and badge only; the ID and consents stay inside the review.
 */
class ApplicantsInCounterSearchTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $dreamGreen;

    private Location $greenhouse;

    private User $staff;

    private MembershipTier $tier;

    private MemberApplication $thomas;

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
        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Locals', 'default_fee_cents' => 1000, 'active' => true]);

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->dreamGreen->id, $this->greenhouse->id]);
        $this->actingAs($owner); // the tablet
        $this->staff = User::factory()->create(['pin' => '3456']);
        $this->staff->assignRole(Role::STAFF->value);
        $this->staff->locations()->sync([$this->dreamGreen->id]);
        CounterOperator::set($this->staff);
        session(['counter.location_id' => $this->dreamGreen->id]);

        $this->thomas = $this->application('Thomas', 'Powney', 'thomas.powney@example.test', $this->dreamGreen);
        $this->application('Thora', 'Rechazada', 'thora@example.test', $this->dreamGreen, ApplicationStatus::REJECTED);
        $this->application('Theo', 'Aprobado', 'theo@example.test', $this->dreamGreen, ApplicationStatus::APPROVED);
        $this->application('Thiago', 'Enespera', 'thiago@example.test', $this->dreamGreen, ApplicationStatus::WAITING_LIST);
        $this->application('Thelma', 'Otrasede', 'thelma@example.test', $this->greenhouse);
    }

    private function application(string $first, string $last, string $email, Location $at, ApplicationStatus $status = ApplicationStatus::PENDING): MemberApplication
    {
        return MemberApplication::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $at->id, 'status' => $status,
            'submitted_at' => now()->setTime(11, 7), 'payload' => [
                'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => '600000000',
                'date_of_birth' => '1990-01-01', 'document_type' => 'DNI', 'document_number' => 'PRUEBA-1',
            ]]);
    }

    // --- 1. Finding an applicant -----------------------------------------------------------------------------------------------

    public function test_the_search_finds_this_sedes_pending_applicants_on_socios_and_the_dispensary(): void
    {
        (new OpenTill)->handle($this->dreamGreen, 'POS-1', 10000);

        foreach ([MembershipCounter::class, DispensaryPos::class] as $screen) {
            foreach (['th', 'powney', 'POWNEY', 'thomas.powney@'] as $term) {
                $html = Livewire::test($screen)->set('lookup', $term)->html();

                $this->assertStringContainsString('Thomas Powney', $html, "{$screen} «{$term}» did not find the applicant");
                $this->assertStringContainsString(__('Solicitudes pendientes'), $html);
                $this->assertStringContainsString(__('Pendiente de aprobar'), $html);
                $this->assertStringContainsString(__('Enviada el :date', ['date' => local_datetime(now()->setTime(11, 7), 'd/m H:i', $this->dreamGreen)]), $html); // the sede's time
                foreach (['Rechazada', 'Aprobado', 'Enespera', 'Otrasede'] as $never) {
                    $this->assertStringNotContainsString($never, $html, "{$screen} showed {$never}");
                }
                $this->assertStringNotContainsString('PRUEBA-1', $html, 'the document number reached the result');
            }
        }
    }

    // --- 2. Approving from the search, on Socios ---------------------------------------------------------------------------------

    public function test_tapping_on_socios_opens_the_review_and_approving_lands_on_the_new_member_with_the_fee(): void
    {
        $page = Livewire::test(MembershipCounter::class)->set('lookup', 'th')->call('openApplication', $this->thomas->id)
            ->assertSet('altaApplicationId', $this->thomas->id)->assertSet('altaOpen', true);

        $page->set('altaTierId', $this->tier->id)->call('approveAlta');

        $member = Member::query()->where('first_name', 'Thomas')->where('last_name', 'Powney')->sole();
        $this->assertSame($member->id, $this->thomas->fresh()->resulting_member_id);
        $page->assertSet('feeMemberId', $member->id);

        (new OpenTill)->handle($this->dreamGreen, 'POS-1', 10000);
        $page->set('feeAmount', '10')->call('collectFee');
        $this->assertSame(1000, MembershipFeePayment::query()->sole()->amount_cents->cents);
    }

    // --- 3. From the dispensary: to Socios; forged ids ignored -----------------------------------------------------------------------

    public function test_tapping_elsewhere_goes_to_socios_with_the_application_and_a_forged_id_is_ignored(): void
    {
        (new OpenTill)->handle($this->dreamGreen, 'POS-1', 10000);
        Livewire::test(DispensaryPos::class)->set('lookup', 'th')->call('openApplication', $this->thomas->id)
            ->assertRedirect(route('counter.members', ['solicitud' => $this->thomas->id]));

        Livewire::withQueryParams(['solicitud' => $this->thomas->id])->test(MembershipCounter::class)
            ->assertSet('altaApplicationId', $this->thomas->id)->assertSet('altaOpen', true);

        $elsewhere = MemberApplication::query()->withoutGlobalScopes()->where('location_id', $this->greenhouse->id)->sole();
        $rejected = MemberApplication::query()->withoutGlobalScopes()->where('status', ApplicationStatus::REJECTED->value)->sole();
        foreach ([$elsewhere->id, $rejected->id, 'nope'] as $forged) {
            Livewire::withQueryParams(['solicitud' => $forged])->test(MembershipCounter::class)->assertSet('altaApplicationId', null);
            Livewire::test(MembershipCounter::class)->call('reviewAltaApplication', $forged)->assertSet('altaApplicationId', null);
        }
    }

    // --- 4. Without applications.review ------------------------------------------------------------------------------------------------

    public function test_without_the_review_permission_the_row_says_ask_a_manager_and_nothing_opens(): void
    {
        $this->setRolePermission(Role::STAFF, 'applications.review', false);
        CounterOperator::set($this->staff->fresh());

        $page = Livewire::test(MembershipCounter::class)->set('lookup', 'th');
        $page->assertSee(__('Solicitud pendiente — pide a un responsable que la apruebe'))->assertDontSeeHtml("openApplication('{$this->thomas->id}')");

        $page->call('openApplication', $this->thomas->id)->assertSet('altaApplicationId', null);
        $page->call('reviewAltaApplication', $this->thomas->id)->assertSet('altaApplicationId', null);
    }

    // --- 5–6. The Socios card and the hub --------------------------------------------------------------------------------------------------

    public function test_socios_lists_pending_applications_without_a_search_and_not_when_there_are_none(): void
    {
        Livewire::test(MembershipCounter::class)->assertSeeHtml('data-socios-pending-applications')
            ->assertSee(__('Solicitudes pendientes (:count)', ['count' => 1]))->assertSee('Thomas Powney');

        $this->thomas->update(['status' => ApplicationStatus::APPROVED]);
        Livewire::test(MembershipCounter::class)->assertDontSeeHtml('data-socios-pending-applications');
    }

    public function test_the_hubs_pending_application_alert_opens_socios_on_that_application(): void
    {
        Livewire::withQueryParams(['alert' => DashboardAlert::PENDING_APPLICATIONS->value])->test(MembershipCounter::class)
            ->assertSet('altaApplicationId', $this->thomas->id)->assertSet('altaOpen', true);
    }
}
