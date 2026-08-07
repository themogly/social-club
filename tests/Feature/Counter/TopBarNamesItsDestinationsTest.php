<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CounterHome;
use App\Models\Article;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 206 — the bar carried two controls that both read "go to the main screen".
 *
 * The owner, on the counter hub: *"Dashboard button and Home / Counter is confusing. Come up with a way to
 * solve it."* Two defects, one theme.
 *
 * **1. Two synonyms, and an inverted icon.** `data-counter-home-link` was labelled *Inicio* and went to the
 * counter hub; `data-counter-dashboard` was labelled *Panel*, which `lang/en.json:1593` renders as
 * **Dashboard**, and went to the admin panel. In English an operator read *Home* and *Dashboard* side by
 * side — synonyms, neither naming the application it opens. **And the house glyph was on the admin link**,
 * while the control that actually goes home wore the product name's first letter. The visual language was
 * inverted, which is most of why this read as confusing rather than merely ambiguous.
 *
 * **2. The confirm fired when nothing would be lost.** Home guarded on `counter.dirty`, which the POS
 * screens set from basket length — but prompt 205's `CounterBasket` made the basket survive navigation, so
 * the operator was warned about a loss that could not happen, on the most common action in the product. The
 * file's own comment asserted the opposite ("the confirm now almost never fires"), which is the tell: one of
 * the two was lying. Teaching people to dismiss a warning is worse than not having one, because the same
 * dialog on Administración and Log out is still telling the truth.
 */
class TopBarNamesItsDestinationsTest extends TestCase
{
    use RefreshDatabase;

    /** Every counter screen, since they all render the same shared chrome. */
    private const SCREENS = ['counter.home', 'counter.checkin', 'counter.members', 'counter.till', 'counter.pos', 'counter.bar'];

    /**
     * The classic home glyph. It was on the ADMIN link before this branch; the assertion below fails if it
     * is ever put back there, which is the only way to catch the two being swapped again.
     */
    private const HOME_GLYPH = 'M2.25 12 12 2.25 21.75 12M4.5 9.75v9.75';

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function operator(string $locale = 'es'): User
    {
        $user = User::factory()->create(['name' => 'Club Staff', 'locale' => $locale]);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        return $user;
    }

    private function barHtml(string $route = 'counter.checkin'): string
    {
        return (string) $this->get(route($route))->assertOk()->getContent();
    }

    // --- reading the bar --------------------------------------------------------------------

    private function document(string $html): DOMXPath
    {
        // DOMDocument treats `@click` as an invalid attribute name and shreds the handler across the
        // element — its words then read as attributes, and on a control with no `aria-label` they could
        // read as TEXT. Normalising the shorthand to `x-on:` first is a faithful rewrite (196's idiom) and
        // keeps the accessible name honest.
        $html = (string) preg_replace('/\s@([a-zA-Z][\w.:-]*)=/', ' x-on:$1=', $html);

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /**
     * Every interactive control in the terminal strip, keyed by its data hook.
     *
     * The sede switcher's OPEN MENU is excluded deliberately: a disclosure's menu item repeating its
     * trigger's name is the pattern working, not a collision. Everything that sits in the row itself is in.
     *
     * @return array<string, DOMElement>
     */
    private function controls(string $html): array
    {
        $xpath = $this->document($html);
        $header = $xpath->query('//*[@data-counter-topbar]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $header, 'the shared counter header did not render');

        $found = [];

        foreach ($xpath->query('.//a|.//button', $header) as $index => $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            // Inside the sede dropdown — see the docblock.
            if ($xpath->query('ancestor::*[@data-counter-sede-menu]', $element)->length > 0) {
                continue;
            }

            $hook = 'control-'.$index;
            foreach ($element->attributes as $attribute) {
                if (str_starts_with($attribute->nodeName, 'data-counter-') || $attribute->nodeName === 'data-operator-name-chip') {
                    $hook = $attribute->nodeName;

                    break;
                }
            }

            $found[$hook] = $element;
        }

        return $found;
    }

    /**
     * The accessible name a screen reader would announce: `aria-label` when present, otherwise the text
     * content with `aria-hidden` subtrees removed — which is the rule that made the old bar's letter tile
     * invisible to the name in the first place.
     */
    private function accessibleName(DOMElement $element): string
    {
        if ($element->hasAttribute('aria-label')) {
            return trim($element->getAttribute('aria-label'));
        }

        $clone = $element->cloneNode(true);
        $this->assertInstanceOf(DOMElement::class, $clone);

        $inner = new DOMXPath($clone->ownerDocument);
        foreach (iterator_to_array($inner->query('.//*[@aria-hidden="true"]', $clone)) as $hidden) {
            $hidden->parentNode?->removeChild($hidden);
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $clone->textContent));
    }

    // --- Defect 1: two synonyms ---------------------------------------------------------------

    /**
     * No two controls in the bar answer to the same name — asserted as a SET across everything the row
     * renders, not against the two we happen to know about, because the next collision will be a different
     * pair. Run in both locales: this defect existed only in English, where `"Panel": "Dashboard"` turned
     * two distinct Spanish words into two ways of saying *the main screen*.
     */
    public function test_no_two_controls_in_the_bar_share_an_accessible_name(): void
    {
        foreach (['es', 'en'] as $locale) {
            $this->operator($locale);

            $names = [];
            foreach ($this->controls($this->barHtml()) as $hook => $control) {
                $name = $this->accessibleName($control);
                $this->assertNotSame('', $name, "[{$locale}] {$hook} has NO accessible name");
                $names[$hook] = $name;
            }

            $this->assertGreaterThanOrEqual(5, count($names), "[{$locale}] the bar measured too few controls to be a real check");
            $this->assertSame(
                count($names),
                count(array_unique($names)),
                "[{$locale}] two controls share an accessible name: ".json_encode($names, JSON_UNESCAPED_UNICODE),
            );
        }
    }

    /**
     * Each of the two is named for WHERE IT GOES. "Dashboard" is the one word that could not stay, because
     * the hub is a dashboard too — so it is gone from the bar in both locales.
     */
    public function test_neither_control_is_named_for_a_kind_of_thing(): void
    {
        foreach (['es', 'en'] as $locale) {
            $this->operator($locale);
            $controls = $this->controls($this->barHtml());

            $this->assertArrayHasKey('data-counter-home-link', $controls, "[{$locale}] no home link");
            $this->assertArrayHasKey('data-counter-admin-link', $controls, "[{$locale}] no admin link");

            $home = $this->accessibleName($controls['data-counter-home-link']);
            $admin = $this->accessibleName($controls['data-counter-admin-link']);

            // The club's own counter, and the application the other one opens.
            $this->assertStringContainsString('Club Verde', $home, "[{$locale}] the home control does not say whose terminal this is");
            $this->assertStringContainsString(__('Inicio del mostrador'), $home, "[{$locale}] the home control does not name its destination");
            $this->assertSame(__('Administración'), $admin, "[{$locale}] the admin control does not name its destination");

            // Fails against `main` in English, where this read "Dashboard".
            foreach ($this->controls($this->barHtml()) as $hook => $control) {
                $this->assertNotSame('Dashboard', $this->accessibleName($control), "[{$locale}] {$hook} is still called Dashboard");
            }
        }
    }

    /** The house is on the control that goes home, and the two do not wear the same glyph. */
    public function test_the_home_glyph_is_on_the_link_that_goes_home_and_the_two_icons_differ(): void
    {
        $this->operator();
        $html = $this->barHtml();

        $home = $this->rawControl($html, 'data-counter-home-link', '</a>');
        $admin = $this->rawControl($html, 'data-counter-admin-link', '</a>');

        $this->assertStringContainsString(self::HOME_GLYPH, $home, 'the control that goes home does not wear the home glyph');
        $this->assertStringNotContainsString(self::HOME_GLYPH, $admin, 'the home glyph is on the admin link — that is the inversion this branch fixed');

        $this->assertNotSame(
            $this->glyph($home),
            $this->glyph($admin),
            'the two controls draw the same icon',
        );
    }

    /**
     * A control's markup EXACTLY as served, from its hook to the end of its element. Handler assertions go
     * through here rather than the DOM, because `@click.prevent` is the thing being asserted and it is the
     * thing DOMDocument cannot hold.
     */
    private function rawControl(string $html, string $hook, string $closing): string
    {
        $at = strpos($html, $hook);
        $this->assertNotFalse($at, $hook.' is missing from the bar');

        $start = (int) strrpos(substr($html, 0, $at), '<');
        $end = strpos($html, $closing, $at);
        $this->assertNotFalse($end, $hook.' has no closing '.$closing);

        return substr($html, $start, (int) $end + strlen($closing) - $start);
    }

    /** The first `d="…"` in a control's markup — its icon. */
    private function glyph(string $markup): string
    {
        preg_match('/ d="([^"]+)"/', $markup, $match);
        $this->assertNotEmpty($match, 'a bar control drew no icon at all');

        return $match[1];
    }

    /**
     * The two controls that LEAVE the counter are grouped apart from the ones that stay inside it.
     *
     * Lock and Home change nothing about the session; Administración opens a different application and Log
     * out ends the session. Both of the latter already confirmed unsaved work, which is exactly the tell
     * that they are one group.
     */
    public function test_the_controls_that_leave_the_counter_are_grouped_apart(): void
    {
        $this->operator();
        $xpath = $this->document($this->barHtml());

        foreach (['data-counter-admin-link', 'data-counter-logout'] as $hook) {
            $control = $xpath->query('//*[@'.$hook.']')->item(0);
            $this->assertInstanceOf(DOMElement::class, $control, $hook.' is missing');
            $this->assertGreaterThan(
                0,
                $xpath->query('ancestor::*[@data-counter-leave-group]', $control)->length,
                $hook.' leaves the counter but is not in the group that does',
            );
        }

        foreach (['data-counter-home-link', 'data-counter-lock'] as $hook) {
            $control = $xpath->query('//*[@'.$hook.']')->item(0);
            $this->assertInstanceOf(DOMElement::class, $control, $hook.' is missing');
            $this->assertSame(
                0,
                $xpath->query('ancestor::*[@data-counter-leave-group]', $control)->length,
                $hook.' stays inside the counter and must not be grouped with the controls that leave',
            );
        }
    }

    // --- Defect 2: the confirm that warned about a loss that cannot happen ---------------------

    /**
     * Going home with a basket raises NO confirm, and the basket is still there afterwards.
     *
     * **Fails against `main`**, where the Home link guarded on `counter.dirty` — which both POS screens set
     * from basket length. The second half is what makes the first half correct rather than merely quieter:
     * the warning is dropped because the loss cannot happen, and the loss not happening is asserted.
     */
    public function test_going_home_with_a_basket_raises_no_confirm_and_the_basket_survives(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 120, 'stock' => 10, 'active' => true,
        ]);

        Livewire::test(BarPos::class)->call('addArticle', $article->id);

        // The bar POS flags a basket as `dirty` and NOT as `volatile` — the distinction the fix rests on.
        $pos = $this->barHtml('counter.bar');
        $this->assertStringContainsString('.dirty = ', $pos, 'the bar POS no longer flags a basket as unsaved work at all');
        $this->assertStringNotContainsString('.volatile = ', $pos, 'a session-backed basket must not be flagged as volatile');

        // …so the Home link does not ask about it.
        $home = $this->rawControl($pos, 'data-counter-home-link', '</a>');
        $this->assertStringNotContainsString('dirty', $home, 'Home still warns about a basket it cannot lose');
        $this->assertStringContainsString('volatile', $home, 'Home must still guard work that a navigation WOULD lose');

        // And the basket really is intact after the trip.
        Livewire::test(CounterHome::class)->assertOk();
        $this->assertCount(1, Livewire::test(BarPos::class)->get('basket'), 'the basket did not survive going home');
    }

    /**
     * The till is the one screen where going home DOES lose work: a half-typed arqueo is a plain Livewire
     * property on a screen with no basket persistence. So it sets `volatile` too, and Home asks there.
     */
    public function test_typed_but_unsubmitted_work_still_stops_the_trip_home(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $till = $this->barHtml('counter.till');
        $this->assertStringContainsString('s.volatile = typed', $till, 'the till does not flag typed input as volatile');
        $this->assertStringContainsString('s.dirty = typed', $till, 'volatile work must also be dirty');
    }

    /**
     * Leaving the counter with a basket still confirms — Administración and Log out both — and declining
     * stays put.
     *
     * "Stays put" is structural, not incidental: the link is `@click.prevent`, so declining the confirm
     * short-circuits before `window.location.assign` and the browser never follows the href either; the
     * logout form calls `preventDefault()` on its submit.
     */
    public function test_leaving_the_counter_with_a_basket_still_confirms_and_declining_stays_put(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);
        $html = $this->barHtml('counter.bar');

        $admin = $this->rawControl($html, 'data-counter-admin-link', '</a>');
        $this->assertStringContainsString('@click.prevent', $admin, 'declining must not let the browser follow the href');
        $this->assertStringContainsString('$store.counter?.dirty', $admin);
        $this->assertStringContainsString('window.confirm(', $admin);
        $this->assertStringContainsString(__('Tienes trabajo sin guardar en el mostrador. ¿Seguro que quieres salir?'), $admin);
        // The assign is the RIGHT operand of `||`+`&&`: refuse the confirm and it never runs.
        $this->assertMatchesRegularExpression('/confirm\(.+\)\)\s*&&\s*window\.location\.assign/s', $admin);

        // Log out is a POST form, so its guard is on submit and cancels the submission itself.
        $at = strpos($html, 'data-counter-logout');
        $this->assertNotFalse($at);
        $form = substr($html, max(0, $at - 900), 900);
        $this->assertStringContainsString('$store.counter?.dirty', $form);
        $this->assertStringContainsString('$event.preventDefault()', $form, 'declining must cancel the log out');
    }

    /**
     * No control's ONLY name is a label that CSS hides.
     *
     * `hidden` is `display:none`, which takes a label out of the accessibility tree as well as off the
     * screen — so a control whose name is a `hidden xl:inline` span is anonymous to a screen reader on the
     * tablet it was designed for. The Lock button was exactly that (206 gave it an `aria-label`), and this
     * branch made it matter by raising the label threshold from `lg` to `xl`.
     */
    public function test_no_control_relies_on_a_css_hidden_label_for_its_name(): void
    {
        $this->operator();

        foreach ($this->controls($this->barHtml()) as $hook => $control) {
            if ($control->hasAttribute('aria-label')) {
                continue;
            }

            $inner = new DOMXPath($control->ownerDocument);
            foreach (iterator_to_array($inner->query('.//*[contains(@class, "hidden")]', $control)) as $hiddenLabel) {
                $hiddenLabel->parentNode?->removeChild($hiddenLabel);
            }

            $this->assertNotSame(
                '',
                $this->accessibleName($control),
                "{$hook} has no name once the breakpoint-hidden labels are gone — it needs an aria-label",
            );
        }
    }

    // --- Rules that must survive the rename ----------------------------------------------------

    /** 130's rule: the shared header renders the counter's single `<h1>`, so headings below start at h2. */
    public function test_every_counter_screen_still_renders_exactly_one_h1(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        foreach (self::SCREENS as $route) {
            $html = $this->barHtml($route);
            $this->assertSame(1, substr_count($html, '<h1'), "{$route} does not render exactly one <h1>");
        }
    }

    /**
     * The club's identity is back in the bar, and it is the CLUB's name — not `config('app.name')`, which is
     * the product's (prompt 150 records the same mistake made on club email).
     */
    public function test_the_bar_says_whose_terminal_this_is(): void
    {
        $this->operator();

        foreach (self::SCREENS as $route) {
            $this->assertStringContainsString('Club Verde', $this->barHtml($route), "{$route} does not say which club this is");
        }
    }

    /** A counter-only login with no panel access still sees no way into admin — the rename changes nothing. */
    public function test_a_user_without_panel_access_sees_no_administration_control(): void
    {
        $user = User::factory()->create(['active' => false]);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        $html = $this->barHtml();
        $this->assertStringContainsString('data-counter-topbar', $html);
        $this->assertStringNotContainsString('data-counter-admin-link', $html);
        $this->assertStringContainsString('data-counter-home-link', $html, 'the way home must survive the lockdown');
    }
}
