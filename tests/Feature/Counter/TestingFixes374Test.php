<?php

namespace Tests\Feature\Counter;

use App\Actions\Bar\CommitOrder;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\CashPot;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Livewire\Counter\CounterHome;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TodaySheet;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CashBoxes;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 374 — fixes from testing 369–373: the «which box» instruction gone in 1.5 s, the day sheet adding the two ledgers
 * together, and the small follow-ups (the member's page, the per-terminal merge warning, Telegram's time). The receipt
 * frame's height is layout, so it is proved in the browser (tests/Browser/shoot-receipt-sheet.mjs, prove-374-fixes.mjs).
 */
class TestingFixes374Test extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Genetic $flower;

    private Genetic $edible;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00', 'Europe/Madrid'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'timezone' => 'Europe/Madrid']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        Settings::set('charge_rounding_enabled', false, SettingType::BOOL);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->flower = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Critical Kush']);
        $this->edible = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Gominola', 'product_type' => ProductType::EDIBLE, 'unit_type' => 'UNIT', 'grams_per_unit_cg' => 7]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->flower->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 818, 'expires_on' => now()->addYear()]);
        Batch::factory()->units(20, 20)->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->edible->id, 'location_id' => $this->sede->id,
            'status' => BatchStatus::OPEN, 'price_per_unit_cents' => 400, 'expires_on' => now()->addYear()]);
    }

    private function allApart(): void
    {
        foreach (CashBoxes::SETTINGS as $key) {
            Settings::set($key, 'own', SettingType::STRING, (string) $this->sede->id);
        }
    }

    private function member(): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        (new EnrolMembership)->handle($member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 0]), ['actor' => $this->owner, 'fee_cents' => 0]);

        return $member;
    }

    private function atCounter(): void
    {
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);
    }

    /** A real counter sale: 2 g of Critical Kush and, optionally, one Gominola (€4.00), paid in cash. */
    private function sale(bool $withEdible = true): Testable
    {
        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $this->member()->id)
            ->call('chooseGenetic', $this->flower->id)->set('weightInput', '2')->call('addLine');
        if ($withEdible) {
            $pos->call('chooseGenetic', $this->edible->id)->set('weightInput', '1')->call('addLine');
        }

        return $pos->set('cashTendered', '50')->call('commitDispensation');
    }

    // --- 1. The box instruction stays until the next sale -------------------------------------------------------------------------

    public function test_the_hub_last_sale_line_carries_the_box_instruction_until_the_next_sale(): void
    {
        $this->allApart();
        (new OpenTill)->handle($this->sede, 'POS-1', 10000, ['operator_id' => $this->owner->id]);
        $this->atCounter();
        $box = __('Pon :parts.', ['parts' => CashPot::EDIBLES->boxPhrase(Money::fromCents(400)->formatted())]);

        $this->sale();
        $line = Livewire::test(CounterHome::class)->html();
        $this->assertMatchesRegularExpression('/data-hub-last-sale.*'.preg_quote(e($box), '/').'/s', $line, 'the hub line says which box');
        $this->assertStringContainsString('data-last-sale-boxes', $line);

        // The next sale (no edible) replaces it: no box line.
        $this->sale(withEdible: false);
        Livewire::test(CounterHome::class)->assertSeeHtml('data-hub-last-sale')->assertDontSeeHtml('data-last-sale-boxes')->assertDontSee($box);
    }

    public function test_with_no_separate_box_the_hub_line_has_no_extra_text(): void
    {
        (new OpenTill)->handle($this->sede, 'POS-1', 10000, ['operator_id' => $this->owner->id]);
        $this->atCounter();

        $this->sale();
        Livewire::test(CounterHome::class)->assertSeeHtml('data-hub-last-sale')->assertDontSeeHtml('data-last-sale-boxes')->assertDontSee(__('Pon'));
    }

    public function test_on_stay_the_box_message_waits_for_the_next_action_instead_of_a_timer(): void
    {
        $this->allApart();
        Settings::set('after_recording', 'stay', SettingType::STRING, (string) $this->sede->id);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000, ['operator_id' => $this->owner->id]);
        $this->atCounter();

        $pos = $this->sale();
        $pos->assertSee(CashPot::EDIBLES->boxPhrase(Money::fromCents(400)->formatted()));
        $pos->assertSet('flashKeeps', true);
        $this->assertStringNotContainsString('setTimeout(() => show = false', $pos->html(), 'no timer on a box instruction');

        // A sale with nothing for a box keeps 234's auto-dismissing success.
        $plain = $this->sale(withEdible: false);
        $plain->assertSet('flashKeeps', false);
        $this->assertStringContainsString('setTimeout(() => show = false', $plain->html());
    }

    // --- 3. The day sheet keeps the two ledgers apart --------------------------------------------------------------------------------

    public function test_the_day_sheet_shows_contributions_equal_to_the_panel_and_bar_and_shop_apart(): void
    {
        $this->allApart();
        $session = (new OpenTill)->handle($this->sede, 'POS-1', 10000, ['operator_id' => $this->owner->id]);
        $member = $this->member();
        $batch = Batch::query()->withoutGlobalScopes()->where('genetic_id', $this->flower->id)->firstOrFail();
        $edibleBatch = Batch::query()->withoutGlobalScopes()->where('genetic_id', $this->edible->id)->firstOrFail();
        (new CommitDispensation)->handle($member, $this->sede, [['genetic_id' => $this->flower->id, 'batch_id' => $batch->id, 'grams_cg' => 200], ['genetic_id' => $this->edible->id, 'batch_id' => $edibleBatch->id, 'units' => 1]],
            ['operator_id' => $this->owner->id, 'till_session_id' => $session->id, 'cash_cents' => 2036]);
        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'price_cents' => 350, 'stock' => 20]);
        (new CommitOrder)->handle($this->sede, [['article_id' => $article->id, 'qty' => 2]], ['operator_id' => $this->owner->id, 'till_session_id' => $session->id, 'cash_cents' => 700]);
        $this->atCounter();

        $sheet = Livewire::test(TodaySheet::class);
        $totals = $sheet->instance()->sheet()->totals();
        $this->assertSame(2036, $totals['money']['dispensary']['total']);
        $this->assertSame(2036, $totals['money']['dispensary']['cash']);
        $this->assertSame(700, $totals['money']['bar']['total']);
        $this->assertArrayNotHasKey('total', $totals['money'], 'no figure adds the two ledgers');

        // The sheet's «Aportaciones» is the home panel's.
        $home = Livewire::test(CounterHome::class)->html();
        preg_match('/data-figure="taken"[^>]*>\s*([^<]+?)\s*</', $home, $m);
        $html = $sheet->html();
        $this->assertSame(Money::fromCents(2036)->formatted(), $m[1] ?? null);
        $this->assertMatchesRegularExpression('/data-sheet-contributions.*'.preg_quote(Money::fromCents(2036)->formatted(), '/').'/s', $html);
        $this->assertMatchesRegularExpression('/data-sheet-bar-money.*'.preg_quote(Money::fromCents(700)->formatted(), '/').'/s', $html);
        $this->assertStringNotContainsString(Money::fromCents(2736)->formatted(), $html);
        $this->assertStringNotContainsString('>'.__('Importe').'<', $html);

        // Its own boxes: one line per box under cash — the till's €16.36, the bar's €7.00, no fees, the edibles' €4.00.
        $this->assertSame(['DISPENSARY' => 1636, 'BAR' => 700, 'SHOP' => 0, 'FEES' => 0, 'EDIBLES' => 400], $totals['boxes']); // 378: the shop box too
        $sheet->assertSeeHtml('data-sheet-boxes')->assertSee(__('Bote de comestibles'));
    }

    // --- 4. Follow-ups -----------------------------------------------------------------------------------------------------------

    public function test_the_members_page_flags_an_address_mail_cannot_reach(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $member->forceFill(['email' => 'juan @gmail.com'])->saveQuietly();

        Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
            ->assertSee('juan @gmail.com')
            ->assertSeeHtml('data-email-invalid');
    }

    public function test_the_merge_warning_names_the_terminal_whose_box_holds_the_money(): void
    {
        Settings::set('cash_box_bar', 'own', SettingType::STRING, (string) $this->sede->id);
        Settings::set('multiple_tills_enabled', true, SettingType::BOOL, (string) $this->sede->id);
        foreach (['POS-1' => 0, 'POS-2' => 4500] as $terminal => $bar) {
            $session = (new OpenTill)->handle($this->sede, $terminal, 10000, ['operator_id' => $this->owner->id]);
            (new CloseTill)->handle($session, 10000, $this->owner, null, ['BAR' => $bar]);
        }
        // POS-1 closed LAST, with an empty bar box: the sede's last close says nothing — POS-2's does.
        TillSession::query()->withoutGlobalScopes()->where('terminal', 'POS-1')->update(['closed_at' => now()->addMinute()]);
        Settings::set('cash_box_bar', 'till', SettingType::STRING, (string) $this->sede->id);

        $warning = (string) CashBoxes::mergeWarning($this->sede, CashPot::BAR);
        $this->assertStringContainsString('POS-2', $warning);
        $this->assertStringContainsString(Money::fromCents(4500)->formatted(), $warning);
        $this->assertStringNotContainsString('POS-1', $warning);
    }

    public function test_the_telegram_rejection_reads_in_the_clubs_time(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['services.telegram.token' => 'test-token', 'services.telegram.username' => 'club_bot']);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 03:27:00', 'UTC'));
        Cache::forever('telegram.token_rejected_at', now()->getTimestamp()); // Telegram::markTokenRejected()'s key

        Livewire::test(SystemHealth::class)->assertSee('9 oct. 05:27')->assertDontSee('9 oct. 03:27');
    }
}
