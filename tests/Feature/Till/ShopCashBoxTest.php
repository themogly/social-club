<?php

namespace Tests\Feature\Till;

use App\Actions\Bar\CommitOrder;
use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\CashMovementType;
use App\Enums\CashPot;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\TillSession as TillScreen;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Location;
use App\Models\Member;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CashBoxes;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use App\Support\TillSummary;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 378 — Ben: "Bar and shop needs to be separate as well": two boxes, drinks and food in the bar's, products and merch in
 * the shop's. Each product says where it is sold (`articles.sold_at`, snapshotted on the order item), the shop's cash is fixed
 * at commit (`orders.shop_cash_cents`, cash to the shop items first), and the shop row on *Cajas* has a third choice, «Con la
 * barra», the default that keeps every sede's figures exactly as they were. One income ledger (Barra y tienda), two boxes.
 */
class ShopCashBoxTest extends TestCase
{
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Medicana']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
    }

    /** @param  array<string, string>  $boxes  pot => choice */
    private function boxes(array $boxes): void
    {
        foreach (CashBoxes::SETTINGS as $pot => $key) {
            Settings::set($key, $boxes[$pot] ?? ($pot === 'SHOP' ? 'with_bar' : 'till'), SettingType::STRING, (string) $this->sede->id);
        }
    }

    private function open(int $float = 10000): TillSession
    {
        return (new OpenTill)->handle($this->sede, 'Caja 1', $float, ['operator_id' => $this->owner->id]);
    }

    private function article(string $name, int $cents, string $soldAt = 'BAR'): Article
    {
        return Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'name' => $name,
            'price_cents' => $cents, 'stock' => 20, 'sold_at' => $soldAt]);
    }

    /** A €3.00 drink and a €12.00 T-shirt. */
    private function order(TillSession $session, int $cash = 1500, int $wallet = 0): Order
    {
        $drink = $this->article('Agua', 300);
        $shirt = $this->article('Camiseta', 1200, 'SHOP');

        return (new CommitOrder)->handle($this->sede, [['article_id' => $drink->id, 'qty' => 1], ['article_id' => $shirt->id, 'qty' => 1]],
            ['operator_id' => $this->owner->id, 'till_session_id' => $session->id, 'member_id' => $this->member->id, 'cash_cents' => $cash, 'wallet_cents' => $wallet]);
    }

    // --- 1. The product says where it is sold, and the order remembers ---------------------------------------------------------

    public function test_a_shop_product_is_snapshotted_as_shop_and_changing_it_later_leaves_the_old_order(): void
    {
        $order = $this->order($this->open());
        $items = collect($order->items)->keyBy('name');
        $this->assertSame('BAR', $items['Agua']['sold_at']);
        $this->assertSame('SHOP', $items['Camiseta']['sold_at']);

        Article::query()->where('name', 'Camiseta')->update(['sold_at' => 'BAR']);
        $this->assertSame('SHOP', collect($order->fresh()->items)->keyBy('name')['Camiseta']['sold_at']);
    }

    // --- 2. The shop's cash, fixed at commit -----------------------------------------------------------------------------------------

    public function test_cash_goes_to_the_shop_items_first_up_to_their_total(): void
    {
        $session = $this->open();
        $this->assertSame(1200, $this->order($session, cash: 1500)->shop_cash_cents->cents);

        (new RecordWalletTransaction)->handle($this->member, $this->sede, 5000, WalletTransactionType::TOPUP, ['till_session_id' => null]);
        $this->assertSame(500, $this->order($session, cash: 500, wallet: 1000)->shop_cash_cents->cents);
    }

    // --- 3. Shop and bar each in their own box ---------------------------------------------------------------------------------------

    public function test_with_both_boxes_the_bar_expects_three_the_shop_twelve_and_the_close_asks_for_both(): void
    {
        $this->boxes(['BAR' => 'own', 'SHOP' => 'own']);
        $session = $this->open(10000);
        $this->order($session);

        $pots = TillSummary::breakdown($session->fresh())['pots'];
        $this->assertSame(300, $pots['BAR']['expected']);
        $this->assertSame(1200, $pots['SHOP']['expected']);
        $this->assertSame(10000, $pots['DISPENSARY']['expected'], 'the till holds only its float');

        $sentence = CashBoxes::sentence($session->fresh(), [CashPot::BAR->value => 300, CashPot::SHOP->value => 1200]);
        $this->assertSame('Pon '.Money::fromCents(1200)->formatted().' en el bote de la tienda y '.Money::fromCents(300)->formatted().' en el bote de la barra.', $sentence);

        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);
        Livewire::test(TillScreen::class)->call('startClose')->assertSeeHtml('data-pot-count="BAR"')->assertSeeHtml('data-pot-count="SHOP"');
    }

    // --- 4. «Con la barra», the default, keeps today's figures -----------------------------------------------------------------------

    public function test_with_the_bar_the_default_keeps_every_figure_as_it_was(): void
    {
        // No choice made: the whole order goes where the bar's money goes — the till here…
        $session = $this->open(10000);
        $this->order($session);
        $this->assertSame(11500, TillSummary::breakdown($session->fresh())['expected']);
        (new CloseTill)->handle($session->fresh(), 11500, $this->owner);

        // …and the bar box when the bar has one, exactly as 373 did.
        Settings::set('cash_box_bar', 'own', SettingType::STRING, (string) $this->sede->id);
        $second = $this->open(10000);
        $this->order($second);
        $pots = TillSummary::breakdown($second->fresh())['pots'];
        $this->assertSame(1500, $pots['BAR']['expected']);
        $this->assertSame(10000, $pots['DISPENSARY']['expected']);
        $this->assertSame(0, $pots['SHOP']['expected']);
        $this->assertNotContains('SHOP', $second->fresh()->own_boxes);

        // A session from before 378 (no shop choice stored) reads the same.
        $second->forceFill(['shop_box' => null])->save();
        $this->assertSame(1500, TillSummary::breakdown($second->fresh())['pots']['BAR']['expected']);
    }

    // --- 5. Presets and the sentence ---------------------------------------------------------------------------------------------

    public function test_the_presets_set_the_shop_row_and_the_sentence_names_it(): void
    {
        $this->assertSame('till', CashBoxes::PRESETS['all_till']['SHOP']);
        $this->assertSame('with_bar', CashBoxes::PRESETS['fees_apart']['SHOP']);
        $this->assertSame('own', CashBoxes::PRESETS['all_apart']['SHOP']);
        $this->assertSame('Al cerrar se cuenta la caja (dispensario) y los botes de comestibles, barra, tienda y cuotas.', CashBoxes::summary(CashBoxes::PRESETS['all_apart']));
        $this->assertSame('Al cerrar se cuenta la caja (dispensario, comestibles, barra, tienda y cuotas).', CashBoxes::summary(CashBoxes::PRESETS['all_till']));
    }

    // --- 6. A shop box merged ------------------------------------------------------------------------------------------------------

    public function test_a_shop_box_switched_to_the_bar_is_merged_with_an_audited_entry_and_opens_at_zero(): void
    {
        $this->boxes(['SHOP' => 'own']);
        $first = $this->open(10000);
        $this->order($first); // the shop box takes the T-shirt's €12.00, the till the drink's €3.00
        (new CloseTill)->handle($first->fresh(), 10300, $this->owner, null, ['SHOP' => 2000]); // €20.00 counted in the shop box

        $this->boxes(['SHOP' => 'with_bar']); // bar in the till → the shop's money joins the till
        $second = $this->open(10000);

        $merge = CashMovement::query()->where('till_session_id', $second->id)->sole();
        $this->assertSame([CashMovementType::IN, CashPot::DISPENSARY, 2000], [$merge->type, $merge->pot, $merge->amount_cents->cents]);
        $this->assertSame('Bote de la tienda unido a la caja', $merge->reason);
        $this->assertSame(['pot' => 'SHOP', 'amount_cents' => 2000], array_intersect_key(AuditLog::query()->withoutGlobalScopes()->where('action', 'till.box_merged')->sole()->after, ['pot' => 1, 'amount_cents' => 1]));
        $this->assertSame(0, (int) $second->fresh()->getRawOriginal('shop_opening_cents'));
        $this->assertNotContains('SHOP', $second->fresh()->own_boxes);
    }

    // --- 7. Marking products; the setting stays owner-only -------------------------------------------------------------------------

    public function test_a_manager_marks_three_products_shop_in_one_go_but_cannot_move_where_the_cash_goes(): void
    {
        $products = collect(['Camiseta', 'Gorra', 'Mechero'])->map(fn (string $name): Article => $this->article($name, 1000));
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->sede->id]);
        $this->actingAs($manager);

        Livewire::test(ListArticles::class)->callTableBulkAction('mark_shop', $products->all());
        $this->assertSame(['SHOP', 'SHOP', 'SHOP'], $products->map(fn (Article $a): string => (string) $a->fresh()->sold_at)->all());

        Livewire::test(EditLocation::class, ['record' => $this->sede->id])->fillForm(['cash_box_shop' => 'own'])->call('save');
        $this->assertSame('with_bar', Settings::get('cash_box_shop', 'with_bar', (string) $this->sede->id), 'a manager cannot move where the shop money goes');
    }
}
