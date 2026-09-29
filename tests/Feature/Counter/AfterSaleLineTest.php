<?php

namespace Tests\Feature\Counter;

use App\Actions\Stock\IntakeArticle;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Weight;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 300 — after a sale, one small line instead of a receipt-and-void panel. The receipt, the email and the void are
 * behind *Opciones*; the void is a sheet; and the line goes away with the next thing that happens (it used to stay on
 * screen through the next member's whole visit).
 */
class AfterSaleLineTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Genetic $genetic;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = $this->person(Role::OWNER);
        $this->actingAs($this->owner);
        CounterOperator::set($this->owner);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id, 'tier_id' => null, 'price_per_gram_cents' => 1500, 'active' => true]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id, 'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1500]);
        $this->article = (new IntakeArticle)->handle(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'name' => 'Agua', 'price_cents' => 1250, 'active' => true], 20);
    }

    private function person(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->sede->id]);

        return $user;
    }

    private function member(?string $email = 'socio@example.test'): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'email' => $email,
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->sede->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        return $member;
    }

    private function dispensed(?Member $member = null): Testable
    {
        return Livewire::test(DispensaryPos::class)
            ->call('selectMember', ($member ?? $this->member())->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->call('addLine', '1')
            ->set('cashTendered', '15')
            ->call('commitDispensation');
    }

    private function sold(): Testable
    {
        return Livewire::test(BarPos::class)->call('addArticle', $this->article->id)->set('cashTendered', '12,50')->call('commitOrder');
    }

    /** The markup outside the void sheet — what sits on the main screen. */
    private function mainScreen(string $html): string
    {
        return (string) preg_replace('/<div[^>]*data-counter-sheet="void".*?<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/s', '', $html);
    }

    // --- 1–2. The compact line and its options --------------------------------------------------------------------

    public function test_after_a_contribution_there_is_one_line_and_no_open_void_or_email_button(): void
    {
        $pos = $this->dispensed();
        $this->assertNotNull($pos->get('lastDispensationId'));

        $pos->assertSee('data-last-sale', false)
            ->assertSeeText(__('Última: :total · :grams · :time', ['total' => Money::fromCents(1500)->formatted(), 'grams' => Weight::fromCentigrams(100)->formatted(), 'time' => local_datetime(now(), 'H:i', $this->sede)]))
            ->assertDontSee(__('Enviar comprobante por email'))
            ->assertDontSee(__('Última dispensación'));
        $this->assertStringNotContainsString('id="pos-void-reason"', $this->mainScreen($pos->html()), 'the void textarea sits open on the main screen');
        $this->assertStringContainsString('data-counter-sheet="void"', $pos->html());
    }

    public function test_the_options_offer_the_receipt_email_only_with_an_address_and_void_only_with_the_permission(): void
    {
        $this->dispensed()->assertSee('data-last-sale-options', false)
            ->assertSee(__('Ver / imprimir recibo'))->assertSee(__('Enviar por email'))->assertSee(__('Anular…'));

        $this->dispensed($this->member(null))->assertDontSee(__('Enviar por email'));

        $staff = $this->person(Role::STAFF);
        $this->actingAs($staff);
        CounterOperator::set($staff);
        $this->dispensed()->assertSee(__('Ver / imprimir recibo'))->assertDontSee(__('Anular…'));
    }

    // --- 3. Voiding ------------------------------------------------------------------------------------------------

    public function test_the_void_needs_a_reason_and_then_voids_exactly_as_before(): void
    {
        $pos = $this->dispensed();
        $id = $pos->get('lastDispensationId');

        $pos->call('voidLast')->assertSet('flashType', 'error');
        $this->assertSame(DispensationStatus::COMPLETED, Dispensation::query()->withoutGlobalScopes()->findOrFail($id)->status);
        $this->assertStringNotContainsString('wire:confirm', (string) $pos->html(), 'the void still asks through a browser dialog');

        $pos->set('voidReason', 'Peso equivocado')->call('voidLast')->assertSet('lastDispensationId', null);
        $this->assertSame(DispensationStatus::VOIDED, Dispensation::query()->withoutGlobalScopes()->findOrFail($id)->status);
    }

    // --- 4. It clears itself ---------------------------------------------------------------------------------------

    public function test_the_line_goes_with_the_next_line_the_next_member_or_a_change_of_member(): void
    {
        $this->dispensed()->call('chooseGenetic', $this->genetic->id)->call('addLine', '1')
            ->assertSet('lastDispensationId', null)->assertDontSee('data-last-sale', false);

        $this->dispensed()->call('selectMember', $this->member('otro@example.test')->id)
            ->assertSet('lastDispensationId', null)->assertSet('lastOrderId', null);

        $this->dispensed()->call('clearMember')->assertSet('lastDispensationId', null);

        $this->dispensed()->call('addBarItem', $this->article->id)->assertSet('lastDispensationId', null);
    }

    // --- 5. The bar --------------------------------------------------------------------------------------------------

    public function test_the_bar_has_the_same_line_options_sheet_and_clearing(): void
    {
        Settings::set('bar_receipt_enabled', true, SettingType::BOOL, $this->sede->id); // 317: the ticket is off unless a sede offers it
        $bar = $this->sold();
        $id = $bar->get('lastOrderId');
        $this->assertNotNull($id);

        $bar->assertSee('data-last-sale', false)
            ->assertSeeText(__('Última venta: :total · :time', ['total' => Money::fromCents(1250)->formatted(), 'time' => local_datetime(now(), 'H:i', $this->sede)]))
            ->assertSee('data-last-sale-options', false)->assertSee(__('Ver / imprimir ticket'))->assertSee(__('Anular…'))
            ->assertDontSee(__('Enviar por email'));
        $this->assertStringNotContainsString('id="bar-void-reason"', $this->mainScreen($bar->html()));

        $bar->call('voidLast')->assertSet('flashType', 'error');
        $bar->set('voidReason', 'Cobrado dos veces')->call('voidLast')->assertSet('lastOrderId', null);
        $this->assertSame(OrderStatus::VOIDED, Order::query()->withoutGlobalScopes()->findOrFail($id)->status);

        $this->sold()->call('addArticle', $this->article->id)->assertSet('lastOrderId', null)->assertDontSee('data-last-sale', false);
    }
}
