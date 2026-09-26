<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\SetMemberDebtLimit;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Dispensation;
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
use App\Support\Settings;
use App\Support\Wallet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 268 — the cash buttons add up, and the wallet only appears when there is something in it (Ben's tablet photo:
 * "New member One", €54 to charge, tendered "10.00").
 *
 * §1 `quickCash()` OVERWROTE the field: €20, €20, €10 left "10.00" (and in a dot format beside a comma field). Now the
 * notes add, "Justo" sets, "Borrar" clears, the field is Spanish, and a "Falta" line explains the shortfall.
 * §2 the "Monedero" box and rows showed for a member with €0 to spend. Now the input shows only for a positive balance.
 */
class TenderPanelTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->location->id]);
        $this->actingAs($this->owner);
        CounterOperator::set($this->owner);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
    }

    private function member(int $walletCents = 0): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);
        if ($walletCents !== 0) {
            (new RecordWalletTransaction)->handle($member, $this->location, $walletCents,
                $walletCents > 0 ? WalletTransactionType::TOPUP : WalletTransactionType::ADJUSTMENT, ['allow_debt' => true]);
        }

        return $member;
    }

    /** The dispensary with a member held and €54,00 in the basket (5,4 g at €10/g). */
    private function dispensary(int $walletCents = 0): Testable
    {
        return Livewire::test(DispensaryPos::class)
            ->call('selectMember', $this->member($walletCents)->id)
            ->call('chooseGenetic', $this->genetic->id)->set('weightInput', '5,4')->call('addLine');
    }

    // --- §1. The quick-cash buttons ---------------------------------------------------------------------------

    public function test_the_notes_add_up_and_justo_sets_the_exact_amount(): void
    {
        $pos = $this->dispensary()->call('quickCash', 2000)->call('quickCash', 2000)->call('quickCash', 1000);

        $pos->assertSet('cashTendered', '50,00'); // €50, in the panel's Spanish format — not the last note pressed

        $pos->call('quickCash')->assertSet('cashTendered', '54,00'); // "Justo" SETS
    }

    public function test_borrar_clears_the_tendered_amount(): void
    {
        $this->dispensary()->call('quickCash', 2000)->call('clearTendered')->assertSet('cashTendered', '');
    }

    public function test_the_field_reads_both_decimal_styles_but_never_a_thousands_separator(): void
    {
        $pos = $this->dispensary();

        foreach (['60,00', '60.00', '60'] as $typed) {
            $pos->set('cashTendered', $typed)->assertSee(__('Cambio'))->assertSeeHtml('data-change-due');
        }

        // "1.000" is refused as ambiguous (257's rule), never read as €1.
        $pos->set('cashTendered', '1.000')->call('commitDispensation')->assertSet('flashType', 'error');
        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_the_shortfall_is_shown_and_the_commit_still_refused(): void
    {
        $pos = $this->dispensary()->set('cashTendered', '10');

        $pos->assertSee(__('Falta'))->assertSee(e(Money::fromCents(4400)->formatted()), false)->assertSeeHtml('data-cash-shortfall');
        $pos->call('commitDispensation')->assertSet('flashMessage', __('El efectivo entregado no cubre el total.'));
        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());

        $html = $pos->set('cashTendered', '60')->html();
        $this->assertStringNotContainsString('data-cash-shortfall', $html);
        $this->assertStringContainsString(e(Money::fromCents(600)->formatted()), $html); // "Cambio €6,00"
    }

    // --- §2. The wallet, only when there is something in it -----------------------------------------------------

    public function test_the_wallet_box_and_rows_show_only_for_a_positive_balance(): void
    {
        $html = $this->dispensary(0)->html();
        $this->assertStringNotContainsString('id="wallet"', $html, 'a €0 wallet still shows its input');
        $this->assertStringNotContainsString('data-member-wallet', $html, 'a €0 wallet still shows its card line');
        $this->assertStringNotContainsString('data-tender-wallet', $html);

        $html = $this->dispensary(600)->html();
        $this->assertStringContainsString('id="wallet"', $html);
        $this->assertStringContainsString('data-member-wallet', $html);

        // A debt: no input (nothing to spend), but the card shows it — in red.
        $html = $this->dispensary(-500)->html();
        $this->assertStringNotContainsString('id="wallet"', $html);
        $this->assertMatchesRegularExpression('/data-member-wallet[\s\S]*?text-error[^>]*>\s*'.preg_quote(e(Money::fromCents(-500)->formatted()), '/').'/', $html);
    }

    public function test_the_tab_still_works_with_the_wallet_input_hidden(): void
    {
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL, $this->location->id);
        $pos = $this->dispensary(0);
        $member = Member::query()->withoutGlobalScopes()->findOrFail($pos->get('memberId'));
        (new SetMemberDebtLimit)->handle($member, $this->owner, 10000, 'Aprobado');

        $html = $pos->call('$refresh')->html();
        $this->assertStringNotContainsString('id="wallet"', $html);
        $this->assertStringContainsString('data-add-to-tab', $html);

        $pos->call('commitOnTab')->assertSet('flashType', 'success');
        $this->assertSame(-5400, Wallet::balance($member->id, $this->location->id));
    }

    // --- The Bar screen, same rules --------------------------------------------------------------------------------

    public function test_the_bar_screen_adds_up_and_hides_an_empty_wallet(): void
    {
        Settings::set('bar_attach_socio_enabled', true, SettingType::BOOL, $this->location->id);
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 5400, 'stock' => 5, 'active' => true,
        ]);

        $bar = Livewire::test(BarPos::class)
            ->call('addArticle', $article->id)
            ->call('quickCash', 2000)->call('quickCash', 2000)->call('quickCash', 1000)
            ->assertSet('cashTendered', '50,00')
            ->assertSeeHtml('data-cash-shortfall');

        $this->assertStringNotContainsString('id="wallet"', $bar->html(), 'no member attached: no wallet box');

        $bar->call('selectMember', $this->member(0)->id);
        $this->assertStringNotContainsString('id="wallet"', $bar->html(), 'a €0 wallet on the bar still shows its input');
    }
}
