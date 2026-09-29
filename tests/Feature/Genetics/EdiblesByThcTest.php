<?php

namespace Tests\Feature\Genetics;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\ResolveMemberLimits;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Filament\Resources\Genetics\Pages\EditGenetic;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\DispensationLine;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\EdibleEquivalence;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 326 — Ben typed 0.4 g AND 400 mg for one gummy (that would be 100 % THC): "Can we not just use the mg value to
 * work out what's in each edible?" An edible is entered by its THC (mg); what it COUNTS as for the limits and the stock
 * ceiling (`grams_per_unit_cg`) is worked out through ONE club setting, `edible_thc_mg_per_gram` (default 150 —
 * OVERNIGHT-DEFAULT, to confirm with the gestor). Pre-rolls stay by weight. Past dispensations keep their grams.
 */
class EdiblesByThcTest extends TestCase
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
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->centro->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->centro->id]);
        $this->actingAs($this->owner);
    }

    private function edible(string $name, int $thcMg): Genetic
    {
        Livewire::test(CreateGenetic::class)->fillForm(['name' => $name, 'product_type' => ProductType::EDIBLE->value, 'thc_mg_per_unit' => (string) $thcMg])
            ->call('create')->assertHasNoFormErrors();

        return Genetic::query()->where('name', $name)->sole();
    }

    // --- 1–2. The form and the maths ----------------------------------------------------------------------------------------

    public function test_an_edible_is_entered_by_thc_and_its_grams_are_worked_out(): void
    {
        $page = Livewire::test(CreateGenetic::class)->fillForm(['product_type' => ProductType::EDIBLE->value, 'thc_mg_per_unit' => '15'])
            ->assertFormFieldIsHidden('grams_per_unit_g')
            ->assertSee(__('Cuenta como :g g por unidad', ['g' => '0.10']));

        $gummy = $this->edible('Gominola', 15);
        $this->assertSame(15, $gummy->thc_mg_per_unit);
        $this->assertSame(10, $gummy->grams_per_unit_cg, '15 mg at 150 mg/g is 0.10 g');

        $this->assertSame(1, $this->edible('Caramelo suave', 1)->grams_per_unit_cg, 'an edible never counts as zero');
        $this->assertSame(10, EdibleEquivalence::gramsCg(15));
    }

    public function test_a_preroll_is_still_entered_by_weight(): void
    {
        Livewire::test(CreateGenetic::class)->fillForm(['product_type' => ProductType::PREROLL->value])
            ->assertFormFieldIsVisible('grams_per_unit_g')
            ->assertFormFieldExists('grams_per_unit_g', fn ($field): bool => $field->getLabel() === __('Peso por unidad (g)'))
            ->assertFormFieldIsHidden('thc_mg_per_unit');

        Livewire::test(CreateGenetic::class)->fillForm(['name' => 'Porro', 'product_type' => ProductType::PREROLL->value, 'grams_per_unit_g' => '1'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(100, Genetic::query()->where('name', 'Porro')->sole()->grams_per_unit_cg);
    }

    // --- 3. Dispensing counts the worked-out grams ------------------------------------------------------------------------------

    private function dispenseThree(Genetic $gummy): Member
    {
        GeneticPrice::factory()->perUnit(300)->create(['organisation_id' => $this->org->id, 'genetic_id' => $gummy->id, 'location_id' => $this->centro->id]);
        $batch = Batch::factory()->units(50)->create(['organisation_id' => $this->org->id, 'genetic_id' => $gummy->id, 'location_id' => $this->centro->id]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay(), 'daily_limit_cg' => 1000, 'monthly_limit_cg' => 10000]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        (new CommitDispensation)->handle($member, $this->centro, [['genetic_id' => $gummy->id, 'batch_id' => $batch->id, 'units' => 3]]);

        return $member;
    }

    public function test_dispensing_three_counts_three_times_the_worked_out_grams(): void
    {
        $member = $this->dispenseThree($this->edible('Gominola', 15));

        $this->assertSame(30, DispensationLine::query()->sole()->getRawOriginal('grams_cg'));
        $this->assertSame(30, (new ResolveMemberLimits)->handle($member, $this->centro)->dailyUsedCg);
    }

    // --- 4. The setting ---------------------------------------------------------------------------------------------------------

    public function test_changing_the_equivalence_recalculates_every_edible_and_leaves_past_dispensations(): void
    {
        $gummy = $this->edible('Gominola', 15);
        $this->dispenseThree($gummy);

        Livewire::test(ManageSettings::class)->fillForm(['edible_thc_mg_per_gram' => '100'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(15, $gummy->fresh()->grams_per_unit_cg, '15 mg at 100 mg/g is 0.15 g');
        $this->assertSame(30, DispensationLine::query()->sole()->getRawOriginal('grams_cg'), 'a past dispensation was rewritten');
        $audit = AuditLog::query()->where('action', 'settings.updated')->latest('id')->first();
        $this->assertSame(150, (int) ($audit?->before['edible_thc_mg_per_gram'] ?? 0));
        $this->assertSame(100, (int) ($audit?->after['edible_thc_mg_per_gram'] ?? 0));
    }

    // --- 5. Existing edibles ------------------------------------------------------------------------------------------------------

    public function test_the_migration_recalculates_edibles_with_thc_and_health_lists_the_rest(): void
    {
        // The old way: grams typed by hand. One with a THC figure (recalculated), one without (kept, and listed).
        DB::table('genetics')->insert([
            ['id' => (string) str()->ulid(), 'organisation_id' => $this->org->id, 'name' => 'Gominola vieja', 'product_type' => 'EDIBLE', 'unit_type' => 'UNIT',
                'grams_per_unit_cg' => 40, 'thc_mg_per_unit' => 15, 'active' => 1, 'published' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) str()->ulid(), 'organisation_id' => $this->org->id, 'name' => 'Brownie sin cifra', 'product_type' => 'EDIBLE', 'unit_type' => 'UNIT',
                'grams_per_unit_cg' => 50, 'thc_mg_per_unit' => null, 'active' => 1, 'published' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        (require database_path('migrations/2026_09_30_100000_derive_edible_grams_from_thc.php'))->up();

        $this->assertSame(10, (int) DB::table('genetics')->where('name', 'Gominola vieja')->value('grams_per_unit_cg'));
        $this->assertSame(50, (int) DB::table('genetics')->where('name', 'Brownie sin cifra')->value('grams_per_unit_cg'));
        Livewire::test(SystemHealth::class)->assertSee('Brownie sin cifra')->assertDontSee('Gominola vieja');

        $brownie = Genetic::query()->where('name', 'Brownie sin cifra')->sole();
        Livewire::test(EditGenetic::class, ['record' => $brownie->getRouteKey()])->fillForm(['description' => 'x'])->call('save')
            ->assertHasFormErrors(['thc_mg_per_unit']);
    }

    // --- 6. The sanity cap ----------------------------------------------------------------------------------------------------------

    public function test_more_than_1000_mg_per_unit_is_refused(): void
    {
        // Asserted on the error bag: assertHasFormErrors() reads the message's ':' as "rule:parameters".
        $errors = Livewire::test(CreateGenetic::class)->fillForm(['name' => 'Bomba', 'product_type' => ProductType::EDIBLE->value, 'thc_mg_per_unit' => '1001'])
            ->call('create')->errors()->get('data.thc_mg_per_unit');
        $this->assertContains(__('Revisa la cifra: parece demasiado alta para una unidad.'), $errors);
        $this->assertSame(0, Genetic::query()->where('name', 'Bomba')->count());
    }

    // --- The counter and the member menu read an edible by mg ------------------------------------------------------------------

    public function test_the_counter_and_the_member_menu_show_an_edibles_mg(): void
    {
        $gummy = $this->edible('Gominola', 10);
        GeneticPrice::factory()->perUnit(300)->create(['organisation_id' => $this->org->id, 'genetic_id' => $gummy->id, 'location_id' => $this->centro->id]);
        Batch::factory()->units(50)->create(['organisation_id' => $this->org->id, 'genetic_id' => $gummy->id, 'location_id' => $this->centro->id]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        $this->actingAs($member, 'member')->get(route('socio.menu'))->assertOk()->assertSee(__(':mg mg THC', ['mg' => 10]));
    }
}
