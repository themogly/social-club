<?php

namespace Tests\Feature\Bar;

use App\Actions\Bar\CommitOrder;
use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\BarPos;
use App\Models\Article;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 193 — the bar's cart column.
 *
 * Both optional panels (attach a socio, ticket reference) are per-sede and OFF by default, because most bar
 * sales are a coffee for cash and both were occupying the top of the column on every one of them. When off
 * the panel is not rendered at all — not collapsed, not disabled — so the column opens on the Basket.
 *
 * The rule that carries the risk: **the flag governs INPUT, never DISPLAY.** A socio or a reference recorded
 * before the flag was turned off must still appear on the receipt, in the ledger and in reports.
 */
class BarCartPanelsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->user = User::factory()->create();
        $this->user->assignRole(Role::OWNER->value);
        $this->user->locations()->sync([$this->location->id]);
        $this->actingAs($this->user);
        CounterOperator::set($this->user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    private function article(string $name = 'Papel de fumar'): Article
    {
        return Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => $name, 'price_cents' => 90, 'stock' => 10, 'active' => true,
        ]);
    }

    private function flag(string $key, bool $on): void
    {
        Settings::set($key, $on ? '1' : '0', SettingType::BOOL, $this->location->id);
    }

    // --- Default: both off, and the column opens on the basket ----------------------------------------

    public function test_both_panels_are_absent_by_default(): void
    {
        $this->article();
        $html = Livewire::test(BarPos::class)->html();

        $this->assertStringNotContainsString(__('Socio (opcional)'), $html);
        $this->assertStringNotContainsString(__('Referencia del ticket (opcional)'), $html);
    }

    public function test_wallet_is_absent_when_a_socio_cannot_be_attached(): void
    {
        // Offering a tender that can never complete is worse than not offering it: wallet REQUIRES a socio.
        $this->article();
        $html = Livewire::test(BarPos::class)->html();

        $this->assertStringNotContainsString(__('Monedero (€)'), $html);
        $this->assertStringContainsString(__('Efectivo entregado'), $html);   // cash is still there
    }

    public function test_each_panel_appears_when_its_own_flag_is_on(): void
    {
        $this->article();

        $this->flag('bar_attach_socio_enabled', true);
        $html = Livewire::test(BarPos::class)->html();
        $this->assertStringContainsString(__('Socio (opcional)'), $html);
        $this->assertStringContainsString(__('Monedero (€)'), $html);          // wallet returns with the socio
        $this->assertStringNotContainsString(__('Referencia del ticket (opcional)'), $html);

        $this->flag('bar_attach_socio_enabled', false);
        $this->flag('bar_ticket_reference_enabled', true);
        $html = Livewire::test(BarPos::class)->html();
        $this->assertStringContainsString(__('Referencia del ticket (opcional)'), $html);
        $this->assertStringNotContainsString(__('Socio (opcional)'), $html);
    }

    public function test_both_on_renders_both(): void
    {
        $this->article();
        $this->flag('bar_attach_socio_enabled', true);
        $this->flag('bar_ticket_reference_enabled', true);

        $html = Livewire::test(BarPos::class)->html();

        $this->assertStringContainsString(__('Socio (opcional)'), $html);
        $this->assertStringContainsString(__('Referencia del ticket (opcional)'), $html);
    }

    // --- The flag governs input, never display --------------------------------------------------------

    public function test_a_socio_and_reference_recorded_earlier_still_render_with_the_flags_off(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'first_name' => 'Ana', 'last_name' => 'Real']);
        $article = $this->article();
        $till = TillSession::query()->withoutGlobalScopes()->where('location_id', $this->location->id)->first();

        // Recorded while the club still used both fields...
        $order = (new CommitOrder)->handle($this->location, [
            ['article_id' => $article->id, 'qty' => 1],
        ], [
            'operator_id' => $this->user->id,
            'till_session_id' => $till->id,
            'member_id' => $member->id,
            'cash_cents' => 90,
            'wallet_cents' => 0,
            'idempotency_key' => 'test-'.uniqid(),
            'reference' => 'Evento verano',
        ]);

        // ...and both flags are off now.
        $this->flag('bar_attach_socio_enabled', false);
        $this->flag('bar_ticket_reference_enabled', false);

        $receipt = $this->get(route('counter.bar.receipt', ['order' => $order->id]))->assertOk()->getContent();

        $this->assertStringContainsString('Ana Real', $receipt, 'Turning the input off hid a socio already on the ledger.');
        $this->assertStringContainsString('Evento verano', $receipt, 'Turning the input off hid a reference already on the ledger.');
    }

    // --- The list is a list ---------------------------------------------------------------------------

    /**
     * List and grid are two forms of ONE card (prompt 230).
     *
     * 193 asserted this on `data-product-row`, a hook the Bar's own row markup carried. That markup moved into
     * `x-counter.article-card`, shared with the POS, and the distinction is now the component's `layout`
     * prop — so the assertion follows it there rather than pinning a hook that moved. What it protects is
     * unchanged: a list row is a ROW (name and numbers on one line), not the grid tile turned sideways.
     */
    public function test_list_mode_and_grid_mode_are_two_forms_of_the_one_card(): void
    {
        $this->article();

        $card = (string) file_get_contents(resource_path('views/components/counter/article-card.blade.php'));
        $this->assertStringContainsString('flex-row items-center gap-3', $card, 'the list form is not a row');
        $this->assertStringContainsString('flex-col gap-1', $card, 'the grid form is not a tile');

        $list = Livewire::test(BarPos::class)->call('setArticleLayout', 'list')->html();
        $grid = Livewire::test(BarPos::class)->call('setArticleLayout', 'grid')->html();

        $this->assertStringContainsString('flex-row items-center gap-3', $list, 'list mode does not render rows');
        $this->assertStringContainsString('flex-col gap-1', $grid, 'grid mode does not render tiles');
        $this->assertStringContainsString('data-article-card=', $list, 'the Bar is not rendering the shared card');
    }

    public function test_the_thumbnail_column_is_omitted_when_no_article_has_an_image(): void
    {
        // A large empty glyph occupying most of the row is a broken-looking gap, not a design. None of these
        // articles has a photo; the club supplies those, and nothing here fabricates one.
        $this->article();

        $list = Livewire::test(BarPos::class)->set('articleLayout', 'list')->html();

        $this->assertStringNotContainsString('h-12 w-12 shrink-0 items-center', $list);
    }
}
