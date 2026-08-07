<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 199 — one outcome, rendered once.
 *
 * Prompt 193 colocated the commit's outcome with the control, which was right and is kept: the message used
 * to render ~650px from the button that produced it in an 820px viewport. But it **added a second writer
 * instead of moving the first**, so every outcome rendered twice — two live regions with identical text,
 * which announces the same refusal to a screen reader twice and is worse than the distance problem 193 set
 * out to solve.
 *
 * The dispensary had the same defect by a different route: prompt 60's colocated block plus the page-top
 * one, both in the working branch.
 *
 * **These assertions COUNT.** `assertSee` is true of the markup no matter how many copies of it there are,
 * which is exactly the weakness 193 itself identified in `ChargeAlwaysObservableTest` — and exactly what let
 * this through.
 */
class OneOutcomePerCommitTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 20]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    /**
     * How many times a message is RENDERED, not how many times it appears in the response.
     *
     * Livewire serialises the component's public properties into `wire:snapshot`, so `$flashMessage` is in
     * the HTML once more than it is on screen. Counting raw occurrences would therefore be wrong in the
     * other direction — this strips the snapshot first, so the number is what a person would see.
     */
    private function rendered(string $html, string $message): int
    {
        $withoutSnapshot = (string) preg_replace('/wire:snapshot="[^"]*"/', '', $html);

        return substr_count($withoutSnapshot, $message);
    }

    private function sellableGenetic(): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Haze']);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        return $genetic;
    }

    // --- The refusal ------------------------------------------------------------

    public function test_the_bar_renders_an_empty_basket_refusal_exactly_once(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);

        $html = Livewire::test(BarPos::class)->call('commitOrder')->assertOk()->html();

        $this->assertSame(1, $this->rendered($html, __('La cesta está vacía.')), 'one refusal, one live region');
    }

    public function test_the_dispensary_renders_an_empty_basket_refusal_exactly_once(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $member = $this->member();

        $html = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('commitDispensation')
            ->assertOk()
            ->html();

        $this->assertSame(1, $this->rendered($html, __('La cesta está vacía.')), 'one refusal, one live region');
    }

    /**
     * The dispensary's own duplicate, which the two cases above do NOT catch.
     *
     * Prompt 60's colocated block renders only when the basket is non-empty, and the page-top block renders
     * always — so with an EMPTY basket there is one message and with a basket in progress there are two. A
     * test that only ever pressed commit on an empty basket would have called this screen clean.
     */
    public function test_the_dispensary_renders_a_refusal_once_even_with_a_basket_in_progress(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $genetic = $this->sellableGenetic();
        $member = $this->member();

        $html = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3,50')
            ->call('addLine')
            ->set('cashTendered', '0')          // under-tendered → a refusal, with the basket still there
            ->call('commitDispensation')
            ->assertOk()
            ->html();

        $this->assertSame(
            1,
            $this->rendered($html, __('El efectivo entregado no cubre el total.')),
            'one refusal, even with a basket on screen',
        );
    }

    // --- The success ------------------------------------------------------------

    public function test_the_bar_renders_a_successful_charge_exactly_once(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 120, 'stock' => 10, 'active' => true,
        ]);

        $html = Livewire::test(BarPos::class)
            ->call('addArticle', $article->id)
            ->set('cashTendered', '5.00')
            ->call('commitOrder')
            ->assertOk()
            ->html();

        $this->assertSame(1, $this->rendered($html, __('Pedido registrado.')), 'one confirmation, one live region');
    }

    public function test_the_dispensary_renders_a_successful_commit_exactly_once(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $genetic = $this->sellableGenetic();
        $member = $this->member();

        $html = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3,50')
            ->call('addLine')
            ->set('cashTendered', '50.00')
            ->call('commitDispensation')
            ->assertOk()
            ->html();

        $this->assertSame(1, $this->rendered($html, __('Dispensación registrada.')), 'one confirmation, one live region');
    }

    // --- And exactly one LIVE REGION, with the right politeness -----------------

    public function test_a_refusal_is_one_assertive_live_region_and_a_success_is_one_polite_one(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);

        // Refusal: assertive, and the message sits inside that one region.
        $html = Livewire::test(BarPos::class)->call('commitOrder')->html();
        $this->assertSame(1, $this->rendered($html, __('La cesta está vacía.')));
        $this->assertStringContainsString('role="alert"', $html);

        // Success.
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 120, 'stock' => 10, 'active' => true,
        ]);
        $ok = Livewire::test(BarPos::class)
            ->call('addArticle', $article->id)
            ->set('cashTendered', '5.00')
            ->call('commitOrder')
            ->html();

        $this->assertSame(1, $this->rendered($ok, 'aria-live="polite"'), 'a success announces politely, once');
    }
}
