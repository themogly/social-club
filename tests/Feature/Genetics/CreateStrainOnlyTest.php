<?php

namespace Tests\Feature\Genetics;

use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Pages\Reports\ConsumptionReportPage;
use App\Filament\Pages\Reports\DiscountsReportPage;
use App\Filament\Pages\Reports\StockReportPage;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Filament\Resources\Genetics\Pages\ListGenetics;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 320 — Ben: "When creating a genetic, no need to add a batch on creation or a price." *Genéticas → Crear* is the
 * strain only (the same form as *Editar*); stock and its price come afterwards through *Crear lote*, which the created
 * notification opens with the strain already chosen (`?genetic=`). A strain with no batch is an ordinary state that
 * every list handles.
 */
class CreateStrainOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->centro->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->centro->id]);
        $this->actingAs($this->owner);
    }

    // --- 1–3. Creating a strain ------------------------------------------------------------------------------------------

    public function test_creating_a_strain_asks_nothing_about_a_batch_and_creates_only_the_strain(): void
    {
        $page = Livewire::test(CreateGenetic::class);
        foreach (['grams', 'units', 'cost_per_gram_eur', 'batch_no', 'lab_report_path', 'location_id', 'price_per_gram_eur', 'price_per_unit_eur'] as $field) {
            $page->assertFormFieldDoesNotExist($field);
        }

        $page->fillForm(['name' => 'Amnesia Haze', 'product_type' => 'FLOWER', 'thc_pct' => '18.5'])->call('create')->assertHasNoFormErrors();

        $genetic = Genetic::query()->where('name', 'Amnesia Haze')->sole();
        $this->assertSame(1850, $genetic->thc_bp);
        $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, GeneticPrice::query()->withoutGlobalScopes()->count());
    }

    public function test_after_saving_the_list_offers_crear_lote_with_the_strain_chosen(): void
    {
        $page = Livewire::test(CreateGenetic::class)->fillForm(['name' => 'Critical Kush', 'product_type' => 'FLOWER'])->call('create')
            ->assertRedirect(GeneticResource::getUrl('index'));

        $genetic = Genetic::query()->where('name', 'Critical Kush')->sole();
        $url = BatchResource::getUrl('create', ['genetic' => $genetic->id]);
        $actions = collect(session('filament.notifications'))->pluck('actions')->flatten(1); // read before assertNotified empties it
        $this->assertContains($url, $actions->pluck('url')->all(), 'the notification has no «Crear lote» button to this strain');
        $page->assertNotified(__('Genética creada. Añade existencias con «Crear lote».'));

        Livewire::withQueryParams(['genetic' => $genetic->id])->test(CreateBatch::class)->assertSchemaStateSet(['genetic_id' => $genetic->id]);
    }

    public function test_a_foreign_or_invalid_strain_id_on_crear_lote_is_ignored(): void
    {
        $other = Organisation::factory()->create();
        $foreign = Genetic::factory()->create(['organisation_id' => $other->id, 'name' => 'Ajena']);

        foreach ([$foreign->id, 'nope', '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
            Livewire::withQueryParams(['genetic' => $id])->test(CreateBatch::class)->assertSchemaStateSet(['genetic_id' => null]);
        }
    }

    public function test_the_strains_own_photos_save_on_the_strain(): void
    {
        Storage::fake('public');

        Livewire::test(CreateGenetic::class)
            ->fillForm(['name' => 'Moby Dick', 'product_type' => 'FLOWER', 'images' => [UploadedFile::fake()->image('moby.jpg')]])
            ->call('create')->assertHasNoFormErrors();

        $genetic = Genetic::query()->where('name', 'Moby Dick')->sole();
        $this->assertCount(1, (array) $genetic->images);
        Storage::disk('public')->assertExists($genetic->images[0]);
        $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count());
    }

    // --- 4. A strain with no batch is fine everywhere -----------------------------------------------------------------------

    public function test_a_strain_with_no_batch_is_handled_by_every_list_and_report(): void
    {
        Livewire::test(CreateGenetic::class)->fillForm(['name' => 'Sin Lote Todavía', 'product_type' => 'FLOWER'])->call('create');

        // The strains list says so, rather than "Sin precio" or a bare 0.
        Livewire::test(ListGenetics::class)->assertSee('Sin Lote Todavía')->assertSee(__('Sin existencias'));

        // The reports render.
        foreach ([StockReportPage::class, ConsumptionReportPage::class, DiscountsReportPage::class] as $report) {
            Livewire::test($report)->assertOk();
        }

        // The member menu and the counter's catalogue leave it out, without an error.
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);
        $this->actingAs($member, 'member')->get(route('socio.menu'))->assertOk()->assertDontSee('Sin Lote Todavía');

        $this->actingAs($this->owner);
        CounterOperator::set($this->owner);
        session(['counter.location_id' => $this->centro->id]);
        (new OpenTill)->handle($this->centro, 'POS-1', 10000);
        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->assertOk()->assertDontSee('Sin Lote Todavía');
    }

    // --- 5. The 308 name rule still holds (a pin) ---------------------------------------------------------------------------

    public function test_a_duplicate_name_is_still_refused(): void
    {
        Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);

        Livewire::test(CreateGenetic::class)->fillForm(['name' => ' amnésia ', 'product_type' => 'FLOWER'])->call('create')->assertHasFormErrors(['name']);
        $this->assertSame(1, Genetic::query()->count());
    }
}
