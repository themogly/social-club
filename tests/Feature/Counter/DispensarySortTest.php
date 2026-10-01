<?php

namespace Tests\Feature\Counter;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FiltersTheCatalogueLikeTheBrowser;
use Tests\TestCase;

/**
 * Prompt 351 — Liam: "all the weeds are in alphabetical order. Is there any way we can put it in price order?" Aaron:
 * "expensive down to low … Medicana and Famara should be the other way round. Or have it on a switch so you can reverse it."
 */
class DispensarySortTest extends TestCase
{
    use FiltersTheCatalogueLikeTheBrowser;
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Member $member;

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
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth()]);
        (new EnrolMembership)->handle($this->member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 0]), ['actor' => $this->owner, 'fee_cents' => 0]);

        // Alphabetical: Amnesia (9,00), Bubba (12,00), Critical (10,50), Durban (pre-roll, 8,00/ud).
        $this->flower('Amnesia', 900);
        $this->flower('Bubba', 1200);
        $this->flower('Critical', 1050);
        $preroll = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Durban', 'product_type' => ProductType::PREROLL, 'grams_per_unit_cg' => 100]);
        Batch::factory()->units(20)->create(['organisation_id' => $this->org->id, 'genetic_id' => $preroll->id, 'location_id' => $this->sede->id,
            'status' => BatchStatus::OPEN, 'price_per_unit_cents' => 800, 'expires_on' => now()->addYear()]);
    }

    private function flower(string $name, int $pricePerGram, ?string $acquired = null): Genetic
    {
        $genetic = Genetic::query()->where('name', $name)->first()
            ?? Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name, 'product_type' => ProductType::FLOWER]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => $pricePerGram, 'expires_on' => now()->addYear(),
            'acquired_or_harvested_on' => $acquired ?? now()->subMonth()->toDateString()]);

        return $genetic;
    }

    private function html(): string
    {
        session(['counter.location_id' => $this->sede->id]);
        if (! TillSession::query()->withoutGlobalScopes()->where('status', 'OPEN')->exists()) {
            (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        }
        CounterOperator::set($this->owner);

        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->member->id)->html();
    }

    /** @return list<string> the dispensary cards' names, in the order the page renders them */
    private function served(string $html): array
    {
        return array_map(fn (array $card): string => $card['name'], $this->cards($html));
    }

    /** @return list<array{name: string, ranks: array<string, int>, type: string}> */
    private function cards(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $cards = [];
        foreach ((new DOMXPath($dom))->query('//*[@data-catalogue-item="genetics"]') ?: [] as $card) {
            /** @var DOMElement $card */
            $cards[] = ['name' => $card->getAttribute('data-search'), 'type' => $card->getAttribute('data-type'), 'ranks' => [
                'price_desc' => (int) $card->getAttribute('data-rank-price_desc'),
                'price_asc' => (int) $card->getAttribute('data-rank-price_asc'),
                'alpha' => (int) $card->getAttribute('data-rank-alpha'),
            ]];
        }

        return $cards;
    }

    /**
     * The visible names in the order the BROWSER shows them for this switch position (the cards' CSS `order` comes from
     * their `data-rank-<sort>`), under a filter state run through the real catalogue module.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function shownInBrowser(string $html, string $sort, array $state = []): array
    {
        $visible = $this->visibleInBrowser($html, 'genetics', $state);
        $cards = array_values(array_filter($this->cards($html), fn (array $c): bool => in_array($c['name'], $visible, true)));
        usort($cards, fn (array $a, array $b): int => $a['ranks'][$sort] <=> $b['ranks'][$sort]);

        return array_column($cards, 'name');
    }

    // --- 1–2. The sede's default order --------------------------------------------------------------------------------------------

    public function test_highest_first_lists_weight_products_by_price_then_unit_products(): void
    {
        Settings::set('dispensary_sort', 'price_desc', SettingType::STRING, (string) $this->sede->id);

        $this->assertSame(['Bubba', 'Critical', 'Amnesia', 'Durban'], $this->served($this->html()));
    }

    public function test_a_new_sede_defaults_to_highest_first(): void
    {
        $this->assertSame('price_desc', Settings::get('dispensary_sort', null, (string) $this->sede->id));
        $this->assertSame(['Bubba', 'Critical', 'Amnesia', 'Durban'], $this->served($this->html()));
    }

    public function test_lowest_first_and_alphabetical(): void
    {
        Settings::set('dispensary_sort', 'price_asc', SettingType::STRING, (string) $this->sede->id);
        $this->assertSame(['Amnesia', 'Critical', 'Bubba', 'Durban'], $this->served($this->html()));

        Settings::set('dispensary_sort', 'alpha', SettingType::STRING, (string) $this->sede->id);
        $this->assertSame(['Amnesia', 'Bubba', 'Critical', 'Durban'], $this->served($this->html()), 'A–Z is the order before 351');
    }

    public function test_the_sede_setting_is_on_the_location_form(): void
    {
        Livewire::test(EditLocation::class, ['record' => $this->sede->getRouteKey()])
            ->assertSchemaStateSet(['dispensary_sort' => 'price_desc'])
            ->fillForm(['dispensary_sort' => 'price_asc'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('price_asc', Settings::get('dispensary_sort', null, (string) $this->sede->id));
    }

    // --- 3. Which price --------------------------------------------------------------------------------------------------------------

    public function test_the_first_batch_sold_decides_the_price_ties_are_alphabetical_and_no_stock_is_last(): void
    {
        // Amnesia gets an OLDER batch at 13,00 — FEFO sells it first, so Amnesia now leads at 13,00.
        $this->flower('Amnesia', 1300, now()->subYear()->toDateString());
        // Critical ties with Bubba at 12,00 — B before C.
        $this->flower('Critical', 1200, now()->subYear()->toDateString());
        // A strain with a price but no batch: «Sin lote», last.
        $empty = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Acapulco', 'product_type' => ProductType::FLOWER]);
        GeneticPrice::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $empty->id, 'location_id' => $this->sede->id,
            'tier_id' => null, 'price_per_gram_cents' => 9999, 'active' => true]);

        // Amnesia 13,00 · Bubba 12,00 = Critical 12,00 (B before C) · the pre-roll · then the strain with no stock.
        $this->assertSame(['Amnesia', 'Bubba', 'Critical', 'Durban', 'Acapulco'], $this->served($this->html()));
    }

    // --- 4–5. The switch, in the browser ---------------------------------------------------------------------------------------------

    public function test_every_card_carries_its_rank_in_all_three_orders_and_a_filter_keeps_the_chosen_order(): void
    {
        Settings::set('dispensary_sort', 'price_desc', SettingType::STRING, (string) $this->sede->id);
        $html = $this->html();

        $this->assertSame(['Amnesia', 'Critical', 'Bubba', 'Durban'], $this->shownInBrowser($html, 'price_asc'), '€↑');
        $this->assertSame(['Amnesia', 'Bubba', 'Critical', 'Durban'], $this->shownInBrowser($html, 'alpha'), 'A–Z');
        $this->assertSame(['Durban'], $this->shownInBrowser($html, 'price_desc', ['productType' => 'PREROLL']));
        $this->assertSame(['Amnesia', 'Critical', 'Bubba'], $this->shownInBrowser($html, 'price_asc', ['productType' => 'FLOWER']));
        // One container for list and grid: the order is a property of the card, whichever layout shows it.
        $this->assertSame(1, substr_count($html, 'data-genetic-sort-container'));
    }

    public function test_the_switch_is_view_only_and_starts_on_the_sede_default(): void
    {
        Settings::set('dispensary_sort', 'price_asc', SettingType::STRING, (string) $this->sede->id);
        $html = $this->html();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $options = (new DOMXPath($dom))->query('//button[@data-sort-option]');

        $this->assertSame(3, $options->length);
        foreach ($options as $option) {
            /** @var DOMElement $option */
            $this->assertTrue($option->hasAttribute('data-view-only'));
            $this->assertSame($option->getAttribute('data-sort-option') === 'price_asc' ? 'true' : 'false', $option->getAttribute('aria-pressed'));
        }
        $this->assertStringContainsString('\\u0022sortDefault\\u0022:\\u0022price_asc\\u0022', $html, 'the Alpine state starts on the sede default');
    }

    public function test_the_choice_is_remembered_for_the_business_day_at_that_sede_only(): void
    {
        $scope = ['sede' => 'S1', 'date' => '2026-10-01'];
        $ask = fn (?array $stored, array $scope): string => $this->runCatalogueJs(
            'const [stored, scope] = process.argv.slice(1).map((a) => JSON.parse(a));'
            .'console.log(JSON.stringify([m.rememberedSort(stored, scope, "price_desc")]));',
            [$stored, $scope],
        )[0];

        $this->assertSame('price_asc', $ask(['sede' => 'S1', 'date' => '2026-10-01', 'sort' => 'price_asc'], $scope), 'same tablet, same day: kept');
        $this->assertSame('price_desc', $ask(['sede' => 'S1', 'date' => '2026-09-30', 'sort' => 'price_asc'], $scope), 'next business day: the default');
        $this->assertSame('price_desc', $ask(['sede' => 'S2', 'date' => '2026-10-01', 'sort' => 'price_asc'], $scope), 'another sede: its default');
        $this->assertSame('price_desc', $ask(null, $scope), 'nothing stored');
        $this->assertSame('price_desc', $ask(['sede' => 'S1', 'date' => '2026-10-01', 'sort' => 'bogus'], $scope), 'garbage');
    }
}
