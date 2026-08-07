<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\WalletTransactionType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Livewire\Counter\TillSession;
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
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 202 — the confirmation carries the outcome.
 *
 * **The prompt's premise was half wrong and is recorded as such.** It described "two mechanisms" producing
 * two confirmations after a bar charge. There was only ever ONE — a single `$flashMessage` that prompt 193
 * rendered from two places in the view — and prompt 199 had already removed the second render. What was
 * left, and what this branch is about, is the other half: the surviving confirmation said *"Pedido
 * registrado."* and nothing more.
 *
 * That is a real defect on a cash counter. `resetBasketState()` clears `cashTendered`, and the change due is
 * derived from `cashTendered` — so **the message outlived the only number the operator was waiting on**. The
 * basket emptying had already told them the charge went through; the €3,80 they owed the member back was
 * gone before they could read it.
 *
 * So: the outcome is captured from the SETTLED row, before the reset, and rides inside the one live region.
 *
 * The lifetime assertions matter as much as the figure. A stale *"Cambio €5,40"* is worse than no
 * confirmation, because the next operator will act on it.
 */
class ConfirmationCarriesTheOutcomeTest extends TestCase
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

    private function member(int $feeCents = 0): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => $feeCents,
        ]);

        return $member;
    }

    private function article(int $priceCents = 120): Article
    {
        return Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => $priceCents, 'stock' => 50, 'active' => true,
        ]);
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
            'initial_cg' => 500000, 'remaining_cg' => 500000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        return $genetic;
    }

    /**
     * How many times a fragment is RENDERED, not how often it appears in the response.
     *
     * Same helper, same reason, as prompt 199's guard: Livewire serialises public properties into
     * `wire:snapshot`, so counting raw occurrences double-counts anything held in state.
     */
    private function rendered(string $html, string $needle): int
    {
        return substr_count((string) preg_replace('/wire:snapshot="[^"]*"/', '', $html), $needle);
    }

    // --- The figure that was being destroyed ------------------------------------

    /**
     * €5,00 handed over for a €1,20 charge → the confirmation states €3,80 change, AFTER the reset.
     *
     * Both halves are asserted deliberately. `cashTendered` is empty by the time this renders — that is the
     * reset doing its job — and the change is on screen anyway, which is only possible because it was
     * computed before the reset and frozen onto the settled outcome.
     */
    public function test_a_cash_charge_states_the_change_due_and_the_figure_survives_the_reset(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);

        $component = Livewire::test(BarPos::class)
            ->call('addArticle', $this->article(120)->id)
            ->set('cashTendered', '5,00')
            ->call('commitOrder')
            ->assertOk();

        $component->assertSet('cashTendered', '', 'the reset really did clear the field the change came from');
        $this->assertSame([], $component->get('basket'), 'and the basket really is empty');

        $settled = $component->get('settled');
        $this->assertSame(380, $settled['change_cents'], 'the real change, in cents: 500 handed − 120 charged');
        $this->assertSame(120, $settled['total_cents']);

        $html = $component->html();
        $this->assertSame(1, $this->rendered($html, 'data-outcome-change'), 'the change is stated exactly once');
        $this->assertStringContainsString(Money::fromCents(380)->formatted(), $html);
        $this->assertSame(1, $this->rendered($html, 'data-settled-outcome'), 'one outcome block, inside the one live region');
    }

    /** The dispensary does the same, on a weight line — €20,00 handed for 3,50 g at €8,00/g = €8,00 back. */
    public function test_the_dispensary_states_the_change_due_too(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $genetic = $this->sellableGenetic();

        $component = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member()->id)
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '3,50')
            ->call('addLine')
            ->set('cashTendered', '30,00')
            ->call('commitDispensation')
            ->assertOk();

        $settled = $component->get('settled');
        $this->assertSame(2800, $settled['total_cents'], '3,50 g × €8,00/g, in cents');
        $this->assertSame(200, $settled['change_cents'], '3000 handed − 2800 charged');
        $this->assertSame(1, $this->rendered($component->html(), 'data-outcome-change'));
    }

    /** No change, no change line. "Cambio €0,00" is noise on an exact-cash or wallet payment. */
    public function test_an_exact_payment_states_no_change_at_all(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);

        $html = Livewire::test(BarPos::class)
            ->call('addArticle', $this->article(120)->id)
            ->set('cashTendered', '1,20')
            ->call('commitOrder')
            ->assertOk()
            ->html();

        $this->assertSame(0, $this->rendered($html, 'data-outcome-change'), 'nothing to hand back, nothing said');
        $this->assertSame(1, $this->rendered($html, 'data-outcome-total'), 'but the charge is still confirmed');
    }

    /** A split is shown only when it IS a split — repeating the total as "efectivo" tells nobody anything. */
    public function test_the_split_is_shown_only_when_both_tenders_were_used(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);
        $member = $this->member();
        (new RecordWalletTransaction)->handle(
            $member, $this->location, 5000, WalletTransactionType::TOPUP,
        );

        $cashOnly = Livewire::test(BarPos::class)
            ->call('addArticle', $this->article(120)->id)
            ->set('cashTendered', '1,20')
            ->call('commitOrder')
            ->assertOk()
            ->html();
        $this->assertSame(0, $this->rendered($cashOnly, 'data-outcome-split'), 'cash only is not a split');

        $split = Livewire::test(BarPos::class)
            ->call('selectMember', $member->id)
            ->call('addArticle', $this->article(1000)->id)
            ->set('walletInput', '4,00')
            ->set('cashTendered', '6,00')
            ->call('commitOrder')
            ->assertOk();

        $settled = $split->get('settled');
        $this->assertSame(400, $settled['wallet_cents'], '€4,00 off the monedero, in cents');
        $this->assertSame(600, $settled['cash_cents'], 'and €6,00 in cash');
        $this->assertSame(1, $this->rendered($split->html(), 'data-outcome-split'));
    }

    // --- Its lifetime -----------------------------------------------------------

    /** The next basket action ends the previous transaction's confirmation. */
    public function test_the_outcome_clears_on_the_next_basket_action(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);
        $article = $this->article(120);

        $component = Livewire::test(BarPos::class)
            ->call('addArticle', $article->id)
            ->set('cashTendered', '5,00')
            ->call('commitOrder');

        $this->assertNotSame([], $component->get('settled'), 'precondition: there is an outcome to clear');

        $component->call('addArticle', $article->id)->assertOk();

        $this->assertSame([], $component->get('settled'), 'the next basket is not the last one');
        $this->assertSame(0, $this->rendered($component->html(), 'data-settled-outcome'));
    }

    /**
     * It does not survive an operator switch or the lock.
     *
     * This is the one that would actually hurt: prompt 198 made the lock KEEP the basket, deliberately, so
     * work survives a step away from the screen. A confirmation is not work — it is a receipt for a
     * transaction that is over, and the person who reads it next may not be the person it belongs to.
     */
    public function test_the_outcome_does_not_survive_an_operator_switch_or_a_lock(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);
        $article = $this->article(120);

        foreach (['switchOperator', 'lockCounter'] as $transition) {
            // Each transition clears the operator, so the next round has to identify one again.
            CounterOperator::set($this->operator());

            $component = Livewire::test(BarPos::class)
                ->call('addArticle', $article->id)
                ->set('cashTendered', '5,00')
                ->call('commitOrder');

            $this->assertNotSame([], $component->get('settled'), "precondition for {$transition}");

            $component->call($transition)->assertOk();

            $this->assertSame([], $component->get('settled'), "the outcome must not survive {$transition}");
            $this->assertNull($component->get('flashMessage'), "nor the message that carried it ({$transition})");
        }
    }

    /** A stale outcome can never end up sitting under an unrelated message. */
    public function test_any_other_message_drops_the_previous_outcome(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);

        $component = Livewire::test(BarPos::class)
            ->call('addArticle', $this->article(120)->id)
            ->set('cashTendered', '5,00')
            ->call('commitOrder');

        $this->assertNotSame([], $component->get('settled'));

        // An empty basket is refused — a different message entirely, on the same live region.
        $component->call('commitOrder')->assertOk();

        $this->assertSame(__('La cesta está vacía.'), $component->get('flashMessage'));
        $this->assertSame([], $component->get('settled'), '€3,80 change must not sit under "la cesta está vacía"');
    }

    /** A sede switch is a full page load; a fresh mount starts with nothing. */
    public function test_a_fresh_mount_carries_no_outcome(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);

        Livewire::test(BarPos::class)->assertSet('settled', [])->assertOk();
        Livewire::test(DispensaryPos::class)->assertSet('settled', [])->assertOk();
    }

    // --- One pattern, one place, five screens ------------------------------------

    /**
     * The view-tree guard, in the style of prompt 194's one-member-lookup grep.
     *
     * Not "a flash appears" — `assertSee` is true however many copies exist, which is the weakness that let
     * 193's duplicate through in the first place. This asserts the STRUCTURE: no counter screen may print
     * `$flashMessage` itself, because that is how the near-copies happened. There is one block, in
     * `partials/counter-flash.blade.php`, and every screen includes it.
     *
     * Three of them hand-rolled their own until this branch, and the copies had already drifted: Socios had
     * lost `aria-live` altogether, so a fee confirmation was announced to nobody at all.
     */
    public function test_no_counter_screen_prints_the_flash_itself(): void
    {
        $screens = glob(resource_path('views/livewire/counter/*.blade.php')) ?: [];
        $this->assertGreaterThanOrEqual(5, count($screens), 'precondition: the counter screens were found');

        $hosts = [];

        foreach ($screens as $path) {
            $blade = (string) file_get_contents($path);
            $name = basename($path);

            $this->assertSame(
                0,
                preg_match_all('/\{\{\s*\$flashMessage\s*\}\}/', $blade),
                $name.' prints the flash itself — use partials/counter-flash.blade.php',
            );

            if (str_contains($blade, '$flashMessage') || str_contains($blade, 'counter-flash')) {
                $hosts[$name] = substr_count($blade, 'livewire.counter.partials.counter-flash');
            }
        }

        // Every screen that has a flash at all gets it from the one partial.
        foreach ($hosts as $name => $includes) {
            $this->assertGreaterThanOrEqual(1, $includes, $name.' must include the shared flash partial');
        }

        $this->assertSame(
            ['bar-pos.blade.php', 'check-in-screen.blade.php', 'dispensary-pos.blade.php', 'membership-counter.blade.php', 'till-session.blade.php'],
            collect(array_keys($hosts))->sort()->values()->all(),
            'the five commit surfaces, all on the same block',
        );
    }

    /** Recepción: one confirmation, from the shared block, naming who came in. */
    public function test_a_check_in_confirms_once(): void
    {
        $this->operator();
        $member = $this->member();

        $html = Livewire::test(CheckInScreen::class)
            ->call('selectMember', $member->id)
            ->call('checkIn')
            ->assertOk()
            ->html();

        $expected = __(':name ha entrado.', ['name' => $member->fullName()]);
        $this->assertSame(1, $this->rendered($html, $expected), 'one confirmation, one live region');
    }

    /**
     * Caja: a cash movement confirms once AND states the amount.
     *
     * `movementAmount` is cleared before the flash, so "Movimiento registrado." on its own left the operator
     * with a blank field and no way to check what they had just posted — the same defect as the bar's change,
     * one screen over.
     */
    public function test_a_till_movement_confirms_once_and_names_the_amount(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $component = Livewire::test(TillSession::class)
            ->set('movementType', 'IN')
            ->set('movementAmount', '50,00')
            ->set('movementReason', 'Cambio')
            ->call('recordMovement')
            ->assertOk();

        $expected = __('Movimiento registrado: :amount.', ['amount' => Money::fromCents(5000)->formatted()]);
        $this->assertSame($expected, $component->get('flashMessage'));
        $this->assertSame('', $component->get('movementAmount'), 'the field it names has already been cleared');
        $this->assertSame(1, $this->rendered($component->html(), $expected), 'one confirmation, one live region');
    }

    /** Socios: a fee confirms once and names what was taken. */
    public function test_a_fee_collection_confirms_once_and_names_the_amount(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $member = $this->member(2500);

        $component = Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $member->id)
            ->set('feeAmount', '25,00')
            ->set('feeMethod', 'CASH')
            ->call('collectFee')
            ->assertOk();

        $expected = __('Cuota cobrada por completo: :amount.', ['amount' => Money::fromCents(2500)->formatted()]);
        $this->assertSame($expected, $component->get('flashMessage'));
        $this->assertSame(1, $this->rendered($component->html(), $expected), 'one confirmation, one live region');
    }
}
