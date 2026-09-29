<?php

namespace Tests\Feature\Bar;

use App\Actions\Stock\IntakeArticle;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 317 — Ben: "Just hide it for now." The bar ticket (the one document that reads like an invoice, *"Ticket de
 * venta"*) is OFF by default, switched per sede (*Sedes → Barra → Ofrecer ticket de barra*). Off, the bar's *Opciones*
 * has no ticket and its route answers 404 — hiding the link is not the gate. The dispensation receipt is unaffected.
 */
class BarTicketSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private Article $agua;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        app(ActiveScope::class)->setLocation($this->centro->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->centro->id, $this->norte->id]);
        $this->actingAs($owner);
        CounterOperator::set($owner);
        session(['counter.location_id' => $this->centro->id]);
        (new OpenTill)->handle($this->centro, 'POS-1', 10000);
        $this->agua = (new IntakeArticle)->handle(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'name' => 'Agua', 'price_cents' => 150, 'active' => true], 20);
    }

    private function sold(): Testable
    {
        return Livewire::test(BarPos::class)->call('addArticle', $this->agua->id)->set('cashTendered', '1.50')->call('commitOrder');
    }

    public function test_by_default_there_is_no_ticket_in_options_and_the_route_is_refused(): void
    {
        $bar = $this->sold();
        $order = $bar->get('lastOrderId');
        $this->assertNotNull($order);

        $bar->assertSee('data-last-sale', false)->assertDontSee(__('Ver / imprimir ticket'))->assertDontSeeHtml('data-receipt-open');
        $this->get(route('counter.bar.receipt', $order))->assertNotFound();
    }

    public function test_with_nothing_left_in_options_only_the_summary_line_shows(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value); // no order.void
        $staff->locations()->sync([$this->centro->id]);
        CounterOperator::set($staff);

        $this->sold()->assertSee('data-last-sale', false)->assertDontSeeHtml('data-last-sale-options');
    }

    public function test_switched_on_at_the_sede_the_ticket_is_back_as_today(): void
    {
        Settings::set('bar_receipt_enabled', true, SettingType::BOOL, $this->centro->id);

        $bar = $this->sold();
        $bar->assertSee(__('Ver / imprimir ticket'))->assertSeeHtml('data-receipt-open');
        $this->get(route('counter.bar.receipt', $bar->get('lastOrderId')))->assertOk()->assertSee(__('Ticket de venta'));
    }

    public function test_the_switch_is_per_sede(): void
    {
        Settings::set('bar_receipt_enabled', true, SettingType::BOOL, $this->norte->id);

        $bar = $this->sold(); // at Sede Centro
        $bar->assertDontSee(__('Ver / imprimir ticket'));
        $this->get(route('counter.bar.receipt', $bar->get('lastOrderId')))->assertNotFound();
    }

    public function test_the_dispensation_receipt_is_unaffected(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->centro->id, 'tier_id' => null, 'price_per_gram_cents' => 1500, 'active' => true]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->centro->id, 'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1500]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->call('chooseGenetic', $genetic->id)
            ->call('addLine', '1')->set('cashTendered', '15')->call('commitDispensation');

        $pos->assertSeeHtml('data-receipt-open');
        $this->get(route('counter.pos.receipt', $pos->get('lastDispensationId')))->assertOk();
    }
}
