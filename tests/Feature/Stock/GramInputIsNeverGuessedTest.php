<?php

namespace Tests\Feature\Stock;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Members\ImportMembers;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Resources\Dispensations\Pages\ViewDispensation;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Livewire\Counter\DispensaryPos;
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
use App\Support\Weight;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 257 — "1000 g" was stored as 1 g.
 *
 * `Weight::fromGrams` turned every comma into a decimal point and left dots alone, so both `1.000` (a Spanish
 * thousand) and `1,000` (an English thousand) became ONE gram. The tester's tablet submitted a separated value
 * into "Cantidad (g)" on the new-strain wizard and a thousand grams of opening stock became one. The contract is
 * now "no guessing": a single separator followed by one or two digits is a decimal (unambiguous in both
 * conventions — neither groups by one or two), and anything else — a separator in a thousands position, two
 * separators, grouping — is REFUSED where the operator sees it, never reinterpreted.
 */
class GramInputIsNeverGuessedTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->location->id]);
        Filament::setCurrentPanel('admin');
    }

    private function createStrain(string $grams): Testable
    {
        return Livewire::actingAs($this->owner)->test(CreateGenetic::class)
            ->fillForm([
                'name' => 'Amnesia Haze', 'product_type' => 'FLOWER', 'grams' => $grams,
                'cost_per_gram_eur' => 4, 'location_id' => $this->location->id, 'price_per_gram_eur' => 8,
            ])
            ->call('create');
    }

    // --- 1. The reported case, end to end ---------------------------------------------------------------------

    public function test_a_separated_thousand_is_refused_visibly_never_stored_as_one_gram(): void
    {
        foreach (['1.000', '1,000'] as $typed) {
            $this->createStrain($typed)->assertHasFormErrors(['grams']);

            $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count(), "'{$typed}' was stored (as 1 g) instead of refused");
        }
    }

    public function test_a_plain_thousand_is_a_thousand_grams(): void
    {
        $this->createStrain('1000')->assertHasNoFormErrors();

        $this->assertSame(100000, Batch::query()->withoutGlobalScopes()->sole()->getRawOriginal('remaining_cg'));
    }

    // --- 2. A genuine decimal still works ---------------------------------------------------------------------

    public function test_a_genuine_decimal_is_read_in_either_convention(): void
    {
        $this->assertSame(150, Weight::fromGrams('1,5')->centigrams);
        $this->assertSame(150, Weight::fromGrams('1.5')->centigrams);
        $this->assertSame(350, Weight::fromGrams('3,50')->centigrams);
        $this->assertSame(100000, Weight::fromGrams('1000')->centigrams);
        $this->assertSame(100050, Weight::fromGrams('1000,5')->centigrams);

        $this->createStrain('2.5')->assertHasNoFormErrors();
        $this->assertSame(250, Batch::query()->withoutGlobalScopes()->sole()->getRawOriginal('remaining_cg'));
    }

    // --- 3. Ambiguous input never silently succeeds ------------------------------------------------------------

    public function test_ambiguous_strings_throw_rather_than_round_to_a_plausible_wrong_answer(): void
    {
        foreach (['1.000', '1,000', '1.000,00', '1,000.00', '1 000', '1.2.3', '0,125', '', 'abc', '-5'] as $typed) {
            try {
                Weight::fromGrams($typed);
                $this->fail("'{$typed}' was parsed instead of refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->createStrain('1.000,00')->assertHasFormErrors(['grams']);
        $this->assertSame(0, Batch::query()->withoutGlobalScopes()->count());
    }

    // --- 4. The POS pad is untouched --------------------------------------------------------------------------

    public function test_the_pos_pad_still_reads_a_comma_decimal(): void
    {
        $method = new \ReflectionMethod(DispensaryPos::class, 'parseGramsCg');

        $this->assertSame(100050, $method->invoke(new DispensaryPos, '1000,5'));
        $this->assertSame(350, $method->invoke(new DispensaryPos, '3,5'));
        $this->assertNull($method->invoke(new DispensaryPos, '1.000'), 'the pad must refuse a thousands group, not read 1 g');
    }

    // --- 5. The other admin forms -----------------------------------------------------------------------------

    public function test_the_declared_forecast_is_not_divided_by_a_thousand(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'declared_monthly_cg' => 3000]);

        Livewire::actingAs($this->owner)->test(ViewMember::class, ['record' => $member->getRouteKey()])
            ->callAction('updateDeclaredForecast', data: ['declared_monthly_g' => '1.000'])
            ->assertHasActionErrors(['declared_monthly_g']);
        $this->assertSame(3000, (int) $member->fresh()->declared_monthly_cg, 'a separated thousand became 1 g');

        Livewire::actingAs($this->owner)->test(ViewMember::class, ['record' => $member->getRouteKey()])
            ->callAction('updateDeclaredForecast', data: ['declared_monthly_g' => '1000'])
            ->assertHasNoActionErrors();
        $this->assertSame(100000, (int) $member->fresh()->declared_monthly_cg);
    }

    public function test_the_member_import_refuses_a_separated_forecast_instead_of_storing_one_gram(): void
    {
        $adult = now()->subYears(30)->toDateString();
        $path = tempnam(sys_get_temp_dir(), 'members_').'.csv';
        file_put_contents($path, implode("\n", [
            'first_name,last_name,date_of_birth,declared_monthly_g',
            "Juan,Pérez,{$adult},1.000",   // a Spanish thousand — was stored as 1 g
            "Marta,Sanz,{$adult},1000",    // plain — a thousand grams
        ]));

        $report = (new ImportMembers)->import($path);
        @unlink($path);

        $this->assertNotEmpty($report['errors']);
        $this->assertSame(0, Member::query()->withoutGlobalScopes()->where('last_name', 'Pérez')->count());
        $this->assertSame(100000, (int) Member::query()->withoutGlobalScopes()->where('last_name', 'Sanz')->sole()->declared_monthly_cg);
    }

    public function test_the_refund_weight_is_not_divided_by_a_thousand(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => null,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'remaining_cg' => 500000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 1000000, 'monthly_limit_cg' => 1000000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
        ]);
        CounterOperator::set($this->owner);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $dispensation = (new CommitDispensation)->handle($member, $this->location,
            [['genetic_id' => $genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 150000]],
            ['operator_id' => $this->owner->id]);

        Livewire::actingAs($this->owner)->test(ViewDispensation::class, ['record' => $dispensation->getRouteKey()])
            ->callAction('refund', data: ['amount_eur' => '0', 'weight_g' => '1.000', 'destination' => 'STOCK', 'method' => 'WALLET', 'reason' => 'Error'])
            ->assertHasActionErrors(['weight_g']);

        $this->assertSame(350000, $batch->fresh()->getRawOriginal('remaining_cg'), 'a separated thousand refunded 1 g');
    }
}
