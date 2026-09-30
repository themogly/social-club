<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
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
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Period;
use App\Support\Settings;
use App\Support\ZReport;
use App\ViewModels\Reports\DiscountsReport;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 331 — Ben: "Can't add manual amounts on the dispensary like you can on the bar." The Dispensario's *Barra* tab
 * (the combined visit, 263) now has the Bar screen's manual line: the SAME modal partial and the SAME rules (one shared
 * concern), carried to `CommitOrder` exactly as the Bar screen carries it. A bar/shop line only — never cannabis, which
 * must name a batch and grams.
 */
class DispensaryManualBarLineTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private User $operator;

    private TillSession $till;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        GeneticPrice::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN]);

        $this->operator = User::factory()->create();
        $this->operator->assignRole(Role::STAFF->value);
        $this->operator->locations()->sync([$this->location->id]);
        $this->actingAs($this->operator);
        CounterOperator::set($this->operator);
        session(['counter.location_id' => $this->location->id]);
        $this->till = (new OpenTill)->handle($this->location, 'POS-1', 10000, ['operator_id' => $this->operator->id]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        return $member;
    }

    private function pos(): Testable
    {
        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id);
    }

    private function mechero(Testable $pos, string $description = 'Mechero', string $amount = '1,50', string $reason = 'Sin código'): Testable
    {
        return $pos->set('miscDescription', $description)->set('miscAmount', $amount)->set('miscReference', $reason)->call('addMiscLine');
    }

    // --- 1–2. Adding a line, and the visit that carries it ---------------------------------------------------------------------

    public function test_a_manual_line_goes_into_the_visits_bar_basket_and_shows_as_manual(): void
    {
        $pos = $this->mechero($this->pos())
            ->assertSet('barBasket', [['description' => 'Mechero', 'unit_price_cents' => 150, 'reference' => 'Sin código', 'qty' => 1]])
            ->assertSet('miscDescription', '')->assertDispatched('misc-added');

        $html = $pos->html();
        $this->assertStringContainsString('data-bar-line-manual', $html);
        $this->assertStringContainsString('Mechero · '.Money::fromCents(150)->formatted(), $html);

        $pos->call('removeBarItem', 0)->assertSet('barBasket', []);
    }

    public function test_the_combined_visit_commits_the_manual_line_to_the_order_till_receipt_and_z_report(): void
    {
        $pos = $this->pos()->call('chooseGenetic', $this->genetic->id)->set('weightInput', '1')->call('addLine');
        $this->mechero($pos)->set('cashTendered', '20')->call('commitDispensation')
            ->assertSet('lastOrderId', fn ($v): bool => $v !== null)->assertSet('barBasket', []);

        $this->assertSame(1, Dispensation::query()->count());
        $order = Order::query()->sole();
        $this->assertSame(150, $order->total_cents->cents);
        $item = collect($order->items)->sole();
        $this->assertNull($item['article_id'] ?? null);
        $this->assertSame(['Mechero', 150, 'Sin código'], [$item['name'], $item['line_total_cents'], $item['reference']]);

        $this->assertSame(150, ZReport::for($this->till->fresh())['bar_cash']);

        Settings::set('bar_receipt_enabled', true, SettingType::BOOL, $this->location->id);
        $this->get(route('counter.bar.receipt', $order->id))->assertOk()->assertSee('Mechero')->assertSee(Money::fromCents(150)->formatted());
    }

    // --- 3–4. The rules: exactly the Bar screen's --------------------------------------------------------------------------------

    public function test_the_same_refusals_as_the_bar_screen(): void
    {
        foreach ([
            ['', '1,50', 'Sin código', 'Indica una descripción para la línea manual.'],
            ['Mechero', '0', 'Sin código', 'Introduce un importe válido.'],
            ['Mechero', '-2', 'Sin código', 'Introduce un importe válido.'],
            ['Mechero', '1,50', '  ', 'Indica un motivo para la línea manual.'],
        ] as [$description, $amount, $reason, $message]) {
            foreach ([$this->pos(), Livewire::test(BarPos::class)] as $screen) {
                $this->mechero($screen, $description, $amount, $reason)->assertSet('flashMessage', __($message))->assertNotDispatched('misc-added');
            }
        }
        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_operator_without_pos_bar_is_refused_with_the_bar_screens_message(): void
    {
        $this->setRolePermission(Role::STAFF, 'pos.bar', false);
        CounterOperator::set($this->operator->fresh());

        $this->mechero($this->pos())->assertSet('barBasket', [])->assertSet('flashMessage', __('Tu usuario no puede vender en la barra.'));
    }

    // --- 5. One modal, one set of rules -------------------------------------------------------------------------------------------

    public function test_both_screens_render_the_one_shared_modal_and_the_bar_screen_is_unchanged(): void
    {
        $views = resource_path('views/livewire/counter/');
        foreach (['bar-pos.blade.php', 'dispensary-pos.blade.php'] as $view) {
            $this->assertStringContainsString("@include('livewire.counter.partials.manual-line-modal'", (string) file_get_contents($views.$view));
        }
        $copies = collect(glob($views.'{*,*/*}.blade.php', GLOB_BRACE) ?: [])->filter(fn (string $f): bool => str_contains((string) file_get_contents($f), 'id="misc-desc"'));
        $this->assertSame([$views.'partials/manual-line-modal.blade.php'], $copies->values()->all(), 'the modal was copied');

        $this->assertStringContainsString(__('Línea manual de barra'), $this->pos()->html());
        $this->assertStringContainsString('data-manual-line-modal', Livewire::test(BarPos::class)->html());

        // The Bar screen's own line, unchanged.
        $bar = $this->mechero(Livewire::test(BarPos::class));
        $this->assertSame([['type' => 'misc', 'description' => 'Mechero', 'unit_price_cents' => 150, 'qty' => 1, 'reference' => 'Sin código']], $bar->get('basket'));
    }

    // --- 6. 291's report --------------------------------------------------------------------------------------------------------------

    public function test_the_discounts_report_counts_the_dispensarys_manual_line(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->mechero($this->pos())->set('cashTendered', '5')->call('commitDispensation')->assertSet('lastOrderId', fn ($v): bool => $v !== null);

        $cards = collect((new DiscountsReport($this->org->id, [$this->location->id], Period::today()))->summary())->pluck('value', 'key');
        $this->assertSame(trans_choice(':count línea|:count líneas', 1, ['count' => 1]).' · '.Money::fromCents(150)->formatted(), $cards['manual_lines']);
    }
}
