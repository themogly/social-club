<?php

namespace Tests\Feature\Counter;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
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
 * Prompt 368 §1 — the dispensary accepted 4 g of a strain with 2.80 g in stock, without a word: the amount was only checked
 * at commit, after the signature and the payment. Now Añadir / Actualizar / a merge compares the line's resulting amount
 * with what is dispensable for it, refuses it inside the weight panel («Solo hay 2.80 g en el bote.»), offers «Añadir 2.80 g»
 * and, with a sealed reserve, «Rellenar». The commit's own check stays the guard.
 */
class AddTimeStockCheckTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private Member $member;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->sede->id]);
        $this->actingAs($owner);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        (new EnrolMembership)->handle($this->member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 0]), ['actor' => $owner, 'fee_cents' => 0]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Polen de casa']);
        session(['counter.location_id' => $this->sede->id]);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        CounterOperator::set($owner);
    }

    private function jar(int $cg = 280, int $reserveCg = 0): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id,
            'initial_cg' => $cg + $reserveCg, 'remaining_cg' => $cg, 'reserve_cg' => $reserveCg, 'status' => BatchStatus::OPEN,
            'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
    }

    private function pos(): Testable
    {
        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->member->id);
    }

    public function test_four_grams_of_a_two_eighty_jar_is_refused_in_the_weight_panel_and_the_fix_adds_exactly_what_there_is(): void
    {
        $this->jar(280);
        $pos = $this->pos()->call('chooseGenetic', $this->genetic->id)->call('addLine', '4', 'grams');

        $this->assertSame([], $pos->get('basket'), 'nothing is added beyond the stock');
        $pos->assertSet('weightInput', '4') // the typed value stays, to correct
            ->assertSet('activeGeneticId', $this->genetic->id);
        $html = $pos->html();
        $panel = substr($html, (int) strpos($html, 'data-weight-entry'), 20000);
        $this->assertStringContainsString('data-stock-short', $panel, 'the message is inside the weight panel');
        $this->assertStringContainsString('Solo hay 2.80 g en el bote.', $panel);
        $this->assertStringContainsString('Añadir 2.80 g', $panel);
        $this->assertStringNotContainsString('data-stock-short-top-up', $panel, 'no reserve, no «Rellenar»');

        $pos->call('addAvailable');
        $this->assertSame([280], array_column($pos->get('basket'), 'grams_cg'));
        $pos->assertSet('stockShort', null);
    }

    public function test_with_a_sealed_reserve_the_message_offers_rellenar_on_existencias(): void
    {
        $batch = $this->jar(280, 3000);
        $pos = $this->pos()->call('chooseGenetic', $this->genetic->id)->call('addLine', '4', 'grams');

        $this->assertSame([], $pos->get('basket'));
        $pos->assertSee('Hay 30.00 g en reserva')
            ->assertSeeHtml('data-stock-short-top-up')
            ->assertSeeHtml(e(route('counter.stock', ['lote' => $batch->id, 'from' => 'pos'])));
    }

    public function test_a_merge_past_the_jar_is_refused_and_the_fix_tops_the_line_up_to_what_there_is(): void
    {
        $this->jar(280);
        $pos = $this->pos();
        $pos->call('chooseGenetic', $this->genetic->id)->call('addLine', '2', 'grams');
        $pos->call('chooseGenetic', $this->genetic->id)->call('addLine', '1', 'grams');

        $this->assertSame([200], array_column($pos->get('basket'), 'grams_cg'), '2 g + 1 g on a 2.80 g jar is refused');
        // 369 — the refusal says the basket already holds part of the jar.
        $pos->assertSee('Ya tienes 2.00 g en la cesta: solo quedan 0.80 g en el bote.')->assertSee('Añadir 0.80 g');
        $pos->call('addAvailable');
        $this->assertSame([280], array_column($pos->get('basket'), 'grams_cg'));
    }

    public function test_editing_a_line_past_the_jar_is_refused(): void
    {
        $this->jar(280);
        $pos = $this->pos();
        $pos->call('chooseGenetic', $this->genetic->id)->call('addLine', '2', 'grams');
        $pos->call('editLine', 0)->call('addLine', '3', 'grams');

        $this->assertSame([200], array_column($pos->get('basket'), 'grams_cg'));
        $pos->assertSee('Solo hay 2.80 g en el bote.')->assertSee('Actualizar a 2.80 g');
        $pos->call('addAvailable');
        $this->assertSame([280], array_column($pos->get('basket'), 'grams_cg'));
    }

    public function test_within_the_stock_nothing_changes(): void
    {
        $this->jar(280);
        $pos = $this->pos()->call('chooseGenetic', $this->genetic->id)->call('addLine', '2.80', 'grams');

        $this->assertSame([280], array_column($pos->get('basket'), 'grams_cg'));
        $pos->assertDontSeeHtml('data-stock-short');
    }

    public function test_a_manual_lote_is_checked_against_its_own_stock(): void
    {
        Settings::set('dispensary_batch_selection', 'manual', SettingType::STRING, (string) $this->sede->id);
        $small = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 150, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear(), 'acquired_or_harvested_on' => '2026-08-01']);
        $this->jar(5000);

        $pos = $this->pos()->call('chooseGenetic', $this->genetic->id)->call('selectBatch', $small->id)->call('addLine', '2', 'grams');
        $this->assertSame([], $pos->get('basket'), 'the chosen lote holds 1.50 g, however much the strain has');
        $pos->assertSee('Solo hay 1.50 g en el bote.');
    }

    public function test_unit_products_are_checked_in_units(): void
    {
        $preroll = Genetic::factory()->preroll()->create(['organisation_id' => $this->org->id]);
        Batch::factory()->units(3, 10)->create(['organisation_id' => $this->org->id, 'genetic_id' => $preroll->id, 'location_id' => $this->sede->id,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear()]);

        $pos = $this->pos()->call('chooseGenetic', $preroll->id)->call('stepUnits', 3)->call('addLine');
        $this->assertSame([], $pos->get('basket'), '4 units of 3');
        $pos->assertSee('Solo quedan 3 uds.')->assertSee('Añadir 3 uds');
        $pos->call('addAvailable');
        $this->assertSame([3], array_column($pos->get('basket'), 'units'));
    }

    public function test_with_the_whole_jar_already_in_the_basket_the_refusal_says_so_and_keeps_rellenar(): void
    {
        $this->jar(280, 3000);
        $pos = $this->pos();
        $pos->call('chooseGenetic', $this->genetic->id)->call('addLine', '2.80', 'grams');
        $pos->call('chooseGenetic', $this->genetic->id)->call('addLine', '1', 'grams');

        $this->assertSame([280], array_column($pos->get('basket'), 'grams_cg'));
        $pos->assertSee('Ya tienes 2.80 g en la cesta: no queda más en el bote.')
            ->assertSeeHtml('data-stock-short-top-up')
            ->assertDontSeeHtml('data-stock-short-fix'); // nothing left to take
    }
}
