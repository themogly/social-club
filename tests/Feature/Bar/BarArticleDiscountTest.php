<?php

namespace Tests\Feature\Bar;

use App\Actions\Bar\CommitOrder;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Models\Article;
use App\Models\Discount;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberDiscount;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 55 — the bar had no discount path though DiscountAppliesTo::ARTICLE existed from the start.
 * A member's PERCENTAGE discount that applies to ARTICLE/BOTH now discounts their bar order, via the
 * single ResolveArticleDiscount resolver the POS display AND CommitOrder both use (so shown == charged).
 * A guest gets nothing; a cannabis-only (GENETIC) discount never leaks onto the bar.
 */
class BarArticleDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 1000, 'stock' => 20, 'active' => true,
        ]);
    }

    private function memberWithDiscount(DiscountAppliesTo $appliesTo): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $discount = Discount::factory()->create([
            'organisation_id' => $this->org->id, 'kind' => DiscountKind::LOCAL, 'mode' => DiscountMode::PERCENT,
            'value_bp' => 1000, 'value_cents' => null, 'applies_to' => $appliesTo, 'active' => true, // 10%
        ]);
        $discount->locations()->attach($this->location->id);
        MemberDiscount::create(['member_id' => $member->id, 'discount_id' => $discount->id]);

        return $member;
    }

    public function test_a_member_article_discount_is_applied_at_commit(): void
    {
        $member = $this->memberWithDiscount(DiscountAppliesTo::BOTH);

        $order = (new CommitOrder)->handle($this->location, [['article_id' => $this->article->id, 'qty' => 2]], [
            'member_id' => $member->id, 'idempotency_key' => (string) Str::ulid(),
        ]);

        // gross 2000, 10% off = 200 discount, net 1800.
        $this->assertSame(1800, $order->total_cents->cents);
        $this->assertSame(200, $order->items[0]['discount_cents']);
        // The itemised total reconciles with the order total (BarSalesReport safety).
        $this->assertSame($order->total_cents->cents, array_sum(array_column($order->items, 'line_total_cents')));
    }

    public function test_a_guest_gets_no_discount(): void
    {
        $order = (new CommitOrder)->handle($this->location, [['article_id' => $this->article->id, 'qty' => 2]], [
            'idempotency_key' => (string) Str::ulid(),
        ]);

        $this->assertSame(2000, $order->total_cents->cents);
        $this->assertSame(0, $order->items[0]['discount_cents']);
    }

    public function test_a_cannabis_only_genetic_discount_never_applies_to_the_bar(): void
    {
        $member = $this->memberWithDiscount(DiscountAppliesTo::GENETIC);

        $order = (new CommitOrder)->handle($this->location, [['article_id' => $this->article->id, 'qty' => 2]], [
            'member_id' => $member->id, 'idempotency_key' => (string) Str::ulid(),
        ]);

        $this->assertSame(2000, $order->total_cents->cents); // full price — no leak
    }

    public function test_the_pos_display_total_matches_the_charged_total(): void
    {
        $member = $this->memberWithDiscount(DiscountAppliesTo::ARTICLE);

        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        Livewire::test(BarPos::class)
            ->call('selectMember', $member->id)
            ->call('addArticle', $this->article->id)
            ->assertSet('memberId', $member->id)
            ->assertViewHas('basketTotalCents', 900); // €10 − 10% = €9
    }
}
