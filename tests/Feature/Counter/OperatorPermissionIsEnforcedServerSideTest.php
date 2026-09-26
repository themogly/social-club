<?php

namespace Tests\Feature\Counter;

use App\Actions\Attendance\CheckInMember;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Livewire\Counter\TillSession;
use App\Models\Article;
use App\Models\Batch;
use App\Models\CheckIn;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\TillSession as TillSessionModel;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 266 — a permission that hides a control is also checked where the action runs, and it is the PIN OPERATOR's
 * (255). Found: with "Usar la barra" off, a crafted `addBarItem()` + commit on the dispensary still recorded a bar sale.
 * The sweep found the same shape behind every counter screen's core write, whose only gate was the tablet LOGIN's mount
 * check — so on an owner-logged tablet a staff PIN whose role had lost the permission could still do it.
 *
 * The tablet here is logged in as the OWNER (the runbook's install); the operator is STAFF.
 */
class OperatorPermissionIsEnforcedServerSideTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $staff;

    private Member $member;

    private Genetic $genetic;

    private Article $beer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->location->id]);
        $this->actingAs($owner); // an owner-logged tablet

        $this->staff = User::factory()->create();
        $this->staff->assignRole(Role::STAFF->value);
        $this->staff->locations()->sync([$this->location->id]);
        CounterOperator::set($this->staff);

        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
        $this->beer = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => 'Cerveza', 'price_cents' => 150, 'stock' => 20, 'active' => true,
        ]);
        $this->member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);
    }

    // --- The reported gap: bar sales from the dispensary without pos.bar ---------------------------------------

    public function test_without_pos_bar_the_dispensary_refuses_to_add_a_bar_item(): void
    {
        $this->setRolePermission(Role::STAFF, 'pos.bar', false);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('addBarItem', $this->beer->id)
            ->assertSet('barBasket', [])
            ->assertSet('flashType', 'error');
    }

    public function test_without_pos_bar_a_forced_bar_line_is_not_charged_and_the_basket_is_kept(): void
    {
        $this->setRolePermission(Role::STAFF, 'pos.bar', false);
        $line = [['article_id' => $this->beer->id, 'qty' => 2]];

        $pos = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->set('barBasket', $line)
            ->call('commitDispensation')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count(), 'a bar sale was recorded without pos.bar');
        $this->assertSame($line, $pos->get('barBasket'), 'the bar items were silently lost');
        $this->assertSame(20, $this->beer->fresh()->stock);
    }

    public function test_with_pos_bar_the_combined_visit_still_settles_both(): void
    {
        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->call('chooseGenetic', $this->genetic->id)->set('weightInput', '1')->call('addLine')
            ->call('setCatalogueSource', 'bar')->call('addBarItem', $this->beer->id)
            ->call('commitDispensation')
            ->assertSet('flashType', 'success');

        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_without_pos_bar_the_bar_switch_is_not_offered(): void
    {
        $this->setRolePermission(Role::STAFF, 'pos.bar', false);

        Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member->id)
            ->assertDontSeeHtml('data-source-option="bar"')
            ->call('setCatalogueSource', 'bar')
            ->assertSet('catalogueSource', 'genetics');
    }

    // --- The sweep: every screen's core write asks the operator ------------------------------------------------

    public function test_the_standalone_bar_refuses_an_operator_without_pos_bar(): void
    {
        $this->setRolePermission(Role::STAFF, 'pos.bar', false);

        Livewire::test(BarPos::class)
            ->set('basket', [['type' => 'article', 'article_id' => $this->beer->id, 'qty' => 1]])
            ->call('commitOrder')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_the_dispensary_refuses_an_operator_without_pos_use(): void
    {
        $this->setRolePermission(Role::STAFF, 'pos.use', false);

        Livewire::test(DispensaryPos::class)
            ->set('memberId', $this->member->id)
            ->set('basket', [['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 100, 'units' => null]])
            ->call('commitDispensation')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_the_door_refuses_an_operator_without_checkin_manage(): void
    {
        $this->setRolePermission(Role::STAFF, 'checkin.manage', false);

        Livewire::test(CheckInScreen::class)
            ->set('memberId', $this->member->id)
            ->call('checkIn')
            ->assertSet('flashType', 'error');
        $this->assertSame(0, CheckIn::query()->withoutGlobalScopes()->count());

        // …and cannot check someone out either.
        (new CheckInMember)->handle($this->member, $this->location, ['operator_id' => $this->staff->id]);
        Livewire::test(CheckInScreen::class)->set('memberId', $this->member->id)->call('checkOut');
        $this->assertNull(CheckIn::query()->withoutGlobalScopes()->sole()->checked_out_at);
    }

    public function test_opening_a_till_refuses_an_operator_without_till_open(): void
    {
        TillSessionModel::query()->withoutGlobalScopes()->update(['status' => 'CLOSED', 'closed_at' => now()]);
        $this->setRolePermission(Role::STAFF, 'till.open', false);

        Livewire::test(TillSession::class)
            ->set('terminal', 'POS-1')->set('floatInput', '100')
            ->call('open')
            ->assertSet('flashType', 'error');

        $this->assertSame(0, TillSessionModel::query()->withoutGlobalScopes()->where('status', 'OPEN')->count());
    }

    public function test_opening_an_application_review_refuses_an_operator_without_applications_review(): void
    {
        $application = MemberApplication::factory()->submitted()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'payload' => ['first_name' => 'Solicitante', 'last_name' => 'Ajena', 'document_number' => '11223344X'],
        ]);
        $this->setRolePermission(Role::STAFF, 'applications.review', false);

        Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('reviewAltaApplication', $application->id)
            ->assertSet('altaApplicationId', null)
            ->assertDontSee('11223344X');
    }
}
