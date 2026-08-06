<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 170 — the panel had never had the tablet pass the counter got (116, 130, 132). Filament's
 * default sidebar is 320px and permanently open from 1024px up with no toggle, and every iPad in
 * landscape sits above that line. Measured against `main` on /members: 243px of the table off the right
 * at 1180 and 399px at 1024 — taking the row-actions column, where Ver/Editar live, with it.
 *
 * These are the STRUCTURAL guarantees. The layout numbers themselves are measured in a real browser
 * (this repo has no Dusk harness) and recorded in DECISIONS. What is pinned here is everything that
 * would silently revert them:
 *
 *   - the panel setting, so an edit to AdminPanelProvider fails the suite rather than quietly undoing it;
 *   - row actions living inside ONE ActionGroup — the headroom guard, since inside a group an extra
 *     action costs 0px of column width instead of 85–100px, and /members had 17px of headroom;
 *   - the CSS that keeps the actions column on screen and the collapse control at the touch floor;
 *   - and that the presentation change did not widen access.
 */
class PanelTabletLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const THEME = 'resources/css/filament/admin/theme.css';

    /** The tables whose row actions were collapsed into a group, and what each must still expose. */
    private const GROUPED_TABLES = [
        'app/Filament/Resources/Members/Tables/MembersTable.php' => ['ViewAction::make()', 'EditAction::make()'],
        'app/Filament/Resources/Genetics/Tables/GeneticsTable.php' => ['ViewAction::make()', 'EditAction::make()'],
        'app/Filament/Resources/Batches/Tables/BatchesTable.php' => ['recallAction()', 'adjustAction()', 'mermaAction()', 'EditAction::make()'],
    ];

    public function test_the_panel_is_configured_collapsible_on_desktop(): void
    {
        // If a future edit drops sidebarCollapsibleOnDesktop(), this fails rather than the tablet
        // silently going back to a permanently-open 320px sidebar.
        $this->assertTrue(
            Filament::getPanel('admin')->isSidebarCollapsibleOnDesktop(),
            'The admin panel must keep its desktop-collapsible sidebar (prompt 170).'
        );

        // …and NOT the fully-collapsible variant: the icon rail keeps every destination one tap away,
        // which is what staff moving between Socios/Dispensario/Caja need. See DECISIONS.
        $this->assertFalse(Filament::getPanel('admin')->isSidebarFullyCollapsibleOnDesktop());
    }

    // --- The headroom guard ---------------------------------------------------------------------------

    public function test_every_reworked_table_puts_its_row_actions_in_one_group(): void
    {
        // /members' minimum content width was 1041px in a 1056px holder — 15px of headroom — and a
        // labelled row action costs 85–100px, so ANY branch adding one tipped a table off screen
        // (prompt 165 added a third member action against exactly that margin). Inside a group, an
        // extra action costs no column width at all, which is what stops the next feature doing it again.
        foreach (self::GROUPED_TABLES as $file => $expected) {
            $block = $this->recordActionsBlock($file);

            $this->assertStringContainsString('ActionGroup::make([', $block,
                "{$file} renders its row actions as loose labelled buttons. That column is the last one ".
                'and is the first thing to fall off a narrow viewport — wrap them in an ActionGroup.');

            foreach ($expected as $action) {
                $this->assertStringContainsString($action, $block, "{$file} no longer exposes {$action}.");
            }
        }
    }

    public function test_no_row_action_sits_outside_the_group(): void
    {
        // A single action left beside the trigger reinstates the whole problem: the column is as wide as
        // its widest content, so one loose labelled button costs the same as it ever did.
        foreach (array_keys(self::GROUPED_TABLES) as $file) {
            $block = $this->recordActionsBlock($file);
            $groupStart = strpos($block, 'ActionGroup::make([');
            $after = substr($block, (int) $groupStart);
            $groupEnd = strpos($after, '            ]),');

            $outside = $groupEnd === false ? '' : substr($after, (int) $groupEnd);

            $this->assertStringNotContainsString('Action::make(', $outside, "{$file} has a row action outside its group.");
            $this->assertStringNotContainsString('Action::make()', $outside, "{$file} has a row action outside its group.");
        }
    }

    public function test_the_group_did_not_widen_access(): void
    {
        // The presentation changed; who can do what did not. A grouped action is still gated by its own
        // visibility/authorisation, so moving it behind a trigger cannot have granted anything.
        $this->seed(RolePermissionSeeder::class);

        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        foreach (['members.create', 'stock.adjust'] as $permission) {
            $this->assertFalse($staff->can($permission),
                "STAFF must not hold [{$permission}] — the presentation change cannot have granted it.");
        }
    }

    // --- The CSS that carries the rest ------------------------------------------------------------------

    public function test_the_actions_column_is_pinned_so_it_cannot_scroll_off(): void
    {
        // At 820px nothing makes an 11-column table fit, so rather than hide data the actions column is
        // pinned to the right edge of the table's own scroller. Measured: row-action controls outside the
        // viewport went from 10–40 per screen to ZERO at every tablet width.
        $css = $this->theme();

        $this->assertStringContainsString('position: sticky', $css);
        $this->assertStringContainsString('td:last-child', $css);
        $this->assertStringContainsString('.dark .fi-ta-table', $css,
            'The pinned cell needs an opaque dark-mode background too, or scrolled-under columns show through it.');
    }

    public function test_the_horizontal_scroll_is_made_discoverable(): void
    {
        // iOS shows no persistent scrollbar, so a table that scrolls sideways is indistinguishable from
        // one that is simply cut off — half of why this was reported as broken rather than as awkward.
        $this->assertStringContainsString('scrollbar-width: thin', $this->theme());
    }

    public function test_the_collapse_control_meets_the_counter_touch_floor(): void
    {
        // Filament ships it at 36×36. Prompt 98 set ≥24×24 for the panel (mouse); prompts 116/132 set
        // ≥44×44 for the counter (touch). This control exists specifically for a person on a tablet, so
        // 44 is the defensible floor — inheriting 36 would be choosing the mouse number for a touch-only
        // affordance. Measured in the browser: 44×44 at tablet widths, 36×36 on the laptop.
        $css = $this->theme();

        $this->assertStringContainsString('fi-topbar-close-collapse-sidebar-btn', $css);
        $this->assertStringContainsString('min-width: 44px', $css);
        $this->assertStringContainsString('min-height: 44px', $css);
    }

    public function test_the_theme_source_rules_are_undisturbed(): void
    {
        // Prompts 143/151: anything panel-rendered must be scanned by theme.css, and the counter's own
        // views must NOT be. This branch added rules to that file and must not have disturbed either.
        $css = $this->theme();

        $this->assertStringContainsString("@source '../../../../app/Filament/**/*'", $css);
        $this->assertStringContainsString("@source not '../../../../resources/views/livewire/counter/**/*'", $css);
    }

    // --- Fixtures ----------------------------------------------------------------------------------------

    private function theme(): string
    {
        return (string) file_get_contents(base_path(self::THEME));
    }

    /** The `recordActions([...])` block of a table, as written. */
    private function recordActionsBlock(string $file): string
    {
        $source = (string) file_get_contents(base_path($file));

        $start = strpos($source, '->recordActions([');
        $this->assertNotFalse($start, "{$file} has no recordActions block.");

        $end = strpos($source, '->toolbarActions(', (int) $start);

        return substr($source, (int) $start, ($end === false ? strlen($source) : $end) - (int) $start);
    }
}
