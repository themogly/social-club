<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 230 — a sold-out article is visible on both bars, and refused by both servers.
 *
 * The two screens disagreed: the standalone Bar rendered it disabled with its count, the POS excluded it from
 * the feed entirely. An operator on the POS could not see that the coffee had run out and therefore could not
 * know to restock it. The Bar's semantics were the right ones.
 *
 * The disabled attribute is presentation. What matters is that both servers refuse the add and say why — a
 * gate that is only a picture is not a gate (CLAUDE.md), so this asserts the refusal rather than the
 * attribute.
 */
class SoldOutIsVisibleAndRefusedTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    private function article(string $name, int $stock, int $priceCents = 250): Article
    {
        return Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => $name, 'price_cents' => $priceCents, 'stock' => $stock, 'active' => true,
            // Explicit: the factory randomises the threshold, so a stock of 12 was sometimes "low" and the
            // card said "Quedan pocas · 12" instead of "Stock: 12" — a flaky fixture, not a flaky screen.
            'low_stock_threshold' => 2,
        ]);
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    // --- Visible on both --------------------------------------------------------------------------

    /**
     * **A sold-out article renders on BOTH screens, with its count.**
     *
     * Fails against `9478612` on the POS, where the feed excludes it and the card carries no count at all.
     */
    public function test_a_sold_out_article_is_on_both_catalogues(): void
    {
        $this->operator();
        $out = $this->article('Café', 0);
        $this->article('Cerveza', 12);

        $screens = [
            'bar' => Livewire::test(BarPos::class)->html(),
            'pos' => Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id)->call('setCatalogueSource', 'bar')->html(),
        ];

        foreach ($screens as $screen => $html) {
            $this->assertStringContainsString('data-article-card="'.$out->id.'"', $html, "{$screen}: the sold-out article is hidden");
            $this->assertStringContainsString(e(__('Agotado')), $html, "{$screen}: it does not say it is out");
            $this->assertStringContainsString(e(__('Stock')).': 12', $html, "{$screen}: the count an operator needs is missing");
        }
    }

    // --- Refused by both --------------------------------------------------------------------------

    /** The POS refuses a sold-out add and says why. Cannot even be attempted on `main` — the feed hid it. */
    public function test_the_pos_refuses_a_sold_out_add(): void
    {
        $this->operator();
        $out = $this->article('Café', 0);

        $component = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member()->id)
            ->call('setCatalogueSource', 'bar')
            ->call('addBarItem', $out->id);

        $component->assertSet('barBasket', []);
        $this->assertStringContainsString('Café', (string) $component->get('flashMessage'), 'the refusal did not name the article');
        $this->assertSame('warning', $component->get('flashType'));
    }

    /** …and the standalone Bar refuses it the same way, with the same wording. */
    public function test_the_bar_refuses_a_sold_out_add(): void
    {
        $this->operator();
        $out = $this->article('Café', 0);

        $component = Livewire::test(BarPos::class)->call('addArticle', $out->id);

        $component->assertSet('basket', []);
        $this->assertStringContainsString('Café', (string) $component->get('flashMessage'));
    }

    /** The refusal is a LIMIT, not a ban: the last unit sells, the one after it does not. */
    public function test_the_last_unit_sells_and_the_next_does_not(): void
    {
        $this->operator();
        $one = $this->article('Última', 1);

        $component = Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member()->id)
            ->call('addBarItem', $one->id);

        $this->assertCount(1, $component->get('barBasket'));

        $component->call('addBarItem', $one->id);

        $this->assertSame(1, (int) $component->get('barBasket')[0]['qty'], 'the basket went past the stock on hand');
        $this->assertStringContainsString('Última', (string) $component->get('flashMessage'));
    }

    // --- The Bar's column -------------------------------------------------------------------------

    /** The commit carries the live total, and still settles (118's path untouched). */
    public function test_the_bar_commit_carries_its_total_and_still_settles(): void
    {
        $this->operator();
        $beer = $this->article('Cerveza', 10, 250);

        $component = Livewire::test(BarPos::class);
        $this->assertStringNotContainsString(e(__('Cobrar').' · '), $component->html(), 'an empty basket shows a total');

        $component->call('addArticle', $beer->id)->call('addArticle', $beer->id);

        $this->assertStringContainsString(e(Money::fromCents(500)->formatted()), $component->html(), 'the total is not on the commit');

        $component->set('cashTendered', '10')->call('commitOrder');

        $order = Order::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(500, $order->total_cents->cents);
    }

    /** The Bar still sells to a socio the dispensary would block — 225's rule, re-run. */
    public function test_the_bar_still_sells_to_a_blocked_member(): void
    {
        $this->operator();
        $beer = $this->article('Cerveza', 10);

        $blocked = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $blocked->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 2500,
        ]);

        $component = Livewire::test(BarPos::class)->call('selectMember', $blocked->id)->call('addArticle', $beer->id);

        $this->assertCount(1, $component->get('basket'), 'the bar refused a coffee to a socio who owes a fee');
        $this->assertStringNotContainsString('data-blocked-member', $component->html());
    }
}
