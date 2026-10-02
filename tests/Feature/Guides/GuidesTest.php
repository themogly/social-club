<?php

namespace Tests\Feature\Guides;

use App\Actions\Till\OpenTill;
use App\Actions\UnlockOperator;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Pages\Guia;
use App\Filament\Pages\Manual;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Guides\DocsAccess;
use App\Support\Guides\Guide;
use App\Support\Guides\GuideLibrary;
use App\Support\Guides\GuidePdf;
use App\Support\Guides\GuideRenderer;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 353 — *Guías*: the staff and manager guides live in the repo (`resources/guides`) and the app shows them as web
 * pages with a PDF, on the counter (⋯ → Guías), in the panel (Ayuda → Manual) and on a staff phone (`/docs`, by PIN —
 * guides only).
 */
class GuidesTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF_GUIDES = ['counter-quick-start', 'cash-at-the-counter', 'clocking-in-and-out'];

    private Location $sede;

    private User $owner;

    private User $manager;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        foreach (['owner' => [Role::OWNER, '1111'], 'manager' => [Role::MANAGER, '2222'], 'staff' => [Role::STAFF, '3333']] as $prop => [$role, $pin]) {
            $user = User::factory()->create(['pin' => $pin]);
            $user->assignRole($role->value);
            $user->locations()->sync([$this->sede->id]);
            $this->{$prop} = $user;
        }
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
    }

    private function atCounter(User $user): void
    {
        $this->actingAs($user);
        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($user);
    }

    /** @return list<string> */
    private function listed(TestResponse $response): array
    {
        preg_match_all('/data-guide-link="([a-z0-9-]+)"/', $response->getContent(), $m);

        return $m[1];
    }

    // --- 1–2. Who sees what --------------------------------------------------------------------------------------------------

    public function test_the_counter_lists_the_staff_guides_for_staff_and_all_four_for_a_manager(): void
    {
        $this->atCounter($this->staff);
        $this->get('/counter')->assertOk()->assertSee('data-counter-guides', false);
        $this->assertSame(self::STAFF_GUIDES, $this->listed($this->get(route('counter.guides'))->assertOk()));
        $this->get(route('counter.guides.show', 'manager-guide'))->assertNotFound();
        $this->get(route('counter.guides.show', 'cash-at-the-counter'))->assertOk()->assertSee('data-way-back', false);

        $this->atCounter($this->manager);
        $this->assertSame([...self::STAFF_GUIDES, 'manager-guide'], $this->listed($this->get(route('counter.guides'))->assertOk()));
        $this->get(route('counter.guides.show', 'manager-guide'))->assertOk();
    }

    public function test_the_counter_guides_need_the_pin_first(): void
    {
        $this->get(route('counter.guides'))->assertRedirect();
    }

    public function test_the_manual_lists_the_guides_for_the_readers_role(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->manager);
        $manual = Livewire::test(Manual::class);
        foreach (GuideLibrary::all() as $guide) {
            $manual->assertSee($guide->title)->assertSee($guide->summary)
                ->assertSee(__('Actualizada el :date', ['date' => $guide->updated->locale('es')->isoFormat('LL')]));
        }
        $manual->assertSee(Guia::getUrl(['g' => 'manager-guide']), false);

        // A member of staff given the panel: the staff guides only, and the managers' one refused.
        $this->staff->givePermissionTo('panel.access');
        $this->actingAs($this->staff->fresh());
        Livewire::test(Manual::class)->assertSee(GuideLibrary::find('cash-at-the-counter')->title)
            ->assertDontSee(GuideLibrary::find('manager-guide')->title);
        $this->get(Guia::getUrl(['g' => 'manager-guide']))->assertNotFound();
        $this->get(Guia::getUrl(['g' => 'cash-at-the-counter']))->assertOk()->assertSee('data-guide-body', false);
    }

    // --- 3. Pages ------------------------------------------------------------------------------------------------------------

    public function test_a_guide_page_renders_headings_a_table_a_note_and_its_images_with_cache_headers(): void
    {
        $this->atCounter($this->staff);
        $html = $this->get(route('counter.guides.show', 'cash-at-the-counter'))->assertOk()->getContent();

        $this->assertStringContainsString('<h2 id="', $html);
        $this->assertStringContainsString('data-guide-toc', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertMatchesRegularExpression('/<img src="[^"]*guias\/img\/cash-at-the-counter\/[^"?]+\?v=[0-9a-f]{12}" alt="[^"]+" loading="lazy"/', $html);

        preg_match('/<img src="([^"]+)"/', $html, $m);
        $image = $this->get(html_entity_decode($m[1]))->assertOk();
        $this->assertStringContainsString('max-age=31536000', (string) $image->headers->get('Cache-Control'));
    }

    public function test_raw_html_in_a_guide_is_escaped_not_rendered(): void
    {
        $guide = new Guide('planted', 'Planted', 'x', Guide::STAFF, 1, CarbonImmutable::parse('2026-10-02'),
            "## Hello\n\n<script>alert('x')</script>\n\n<img src=x onerror=alert(1)>\n\n[link](javascript:alert(1))\n", []);
        $html = GuideRenderer::render($guide, fn (string $file): string => '/img/'.$file)['html'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_an_image_needs_a_reader_who_may_read_that_guide(): void
    {
        $file = basename(GuideLibrary::find('manager-guide')->images[0]);
        $this->get(route('guides.image', ['manager-guide', $file]))->assertForbidden();
        $this->atCounter($this->staff);
        $this->get(route('guides.image', ['manager-guide', $file]))->assertNotFound();
        $this->get(route('guides.image', ['cash-at-the-counter', '..%2F..%2F.env']))->assertNotFound();
    }

    // --- 4. The PDF ----------------------------------------------------------------------------------------------------------

    public function test_the_pdf_is_a4_with_the_title_and_images_cached_and_regenerated_when_the_source_changes(): void
    {
        Storage::fake('local');
        $this->atCounter($this->staff);
        $guide = GuideLibrary::find('clocking-in-and-out');

        $pdf = $this->get(route('guides.pdf', $guide->slug))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $bytes = (string) $pdf->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertMatchesRegularExpression('/\/MediaBox \[0\.0+ 0\.0+ 595\.2\d+ 841\.8\d+\]/', $bytes, 'A4');
        $this->assertStringContainsString('/Title ('."\xFE\xFF".mb_convert_encoding($guide->title, 'UTF-16BE', 'UTF-8').')', $bytes, 'the title (PDF text string, UTF-16)');
        $this->assertGreaterThanOrEqual(count($guide->images), substr_count($bytes, '/Subtype /Image'));

        // The second request is the stored file…
        $cached = 'guides/pdf/'.$guide->slug.'-'.$guide->contentHash().'.pdf';
        Storage::disk('local')->put($cached, '%PDF-cached');
        $this->assertSame('%PDF-cached', $this->get(route('guides.pdf', $guide->slug))->getContent());

        // …and a changed source renders afresh, replacing the old file.
        $changed = new Guide($guide->slug, $guide->title, $guide->summary, $guide->audience, $guide->order, $guide->updated, $guide->body."\n\nOne more line.\n", $guide->images);
        $this->assertNotSame('%PDF-cached', GuidePdf::bytes($changed));
        Storage::disk('local')->assertMissing($cached);
    }

    // --- 5. /docs: a staff phone, by PIN -------------------------------------------------------------------------------------

    private function docsSignIn(string $pin, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('guides.docs.signin'), ['pin' => $pin]);
    }

    private function docsCookie(TestResponse $response): string
    {
        $cookie = $response->getCookie(DocsAccess::COOKIE);
        $this->assertNotNull($cookie, 'no guides cookie was set');

        return (string) $cookie->getValue();
    }

    public function test_docs_with_a_staff_pin_shows_the_staff_guides_and_a_manager_pin_all_four(): void
    {
        $this->get(route('guides.docs'))->assertOk()->assertSee(__('Introduce tu PIN para ver las guías.'));

        $staff = $this->docsCookie($this->docsSignIn('3333')->assertRedirect(route('guides.docs')));
        $this->assertSame(self::STAFF_GUIDES, $this->listed($this->withCookie(DocsAccess::COOKIE, $staff)->get(route('guides.docs'))));
        $this->withCookie(DocsAccess::COOKIE, $staff)->get(route('guides.docs.show', 'manager-guide'))->assertNotFound();
        $this->withCookie(DocsAccess::COOKIE, $staff)->get(route('guides.docs.show', 'cash-at-the-counter'))->assertOk();
        $this->withCookie(DocsAccess::COOKIE, $staff)->get(route('guides.pdf', 'manager-guide'))->assertNotFound();

        $manager = $this->docsCookie($this->docsSignIn('2222', '10.0.0.2'));
        $this->assertSame([...self::STAFF_GUIDES, 'manager-guide'], $this->listed($this->withCookie(DocsAccess::COOKIE, $manager)->get(route('guides.docs'))));
    }

    public function test_a_wrong_unknown_or_inactive_pin_gets_the_same_message(): void
    {
        $this->staff->update(['active' => false]);
        foreach (['9999', '3333', 'abcd'] as $i => $pin) {
            $response = $this->docsSignIn($pin, '10.1.0.'.$i)->assertRedirect(route('guides.docs'));
            $this->assertNull($response->getCookie(DocsAccess::COOKIE));
            $this->withSession(['docs_error' => true])->get(route('guides.docs'))->assertSee(__('PIN incorrecto'));
        }
    }

    public function test_the_guides_session_reaches_nothing_but_the_guides(): void
    {
        $cookie = $this->docsCookie($this->docsSignIn('1111')); // even the OWNER's PIN

        foreach (['/counter', '/counter/till', '/counter/pos', '/counter/members', '/', '/members', route('counter.guides')] as $url) {
            $response = $this->withCookie(DocsAccess::COOKIE, $cookie)->get($url);
            $this->assertNotSame(200, $response->getStatusCode(), "{$url} answered a guides-only session");
        }
        $this->assertGuest();
    }

    public function test_five_wrong_pins_lock_the_ip_out_and_never_touch_the_counter_throttle(): void
    {
        foreach (range(1, 5) as $i) {
            $this->docsSignIn('99'.$i.'9');
        }
        $this->assertNull($this->docsSignIn('3333')->getCookie(DocsAccess::COOKIE), 'the right PIN is refused while locked out');
        $this->assertNotNull($this->docsSignIn('3333', '10.9.9.9')->getCookie(DocsAccess::COOKIE), 'another IP is not locked');
        $this->assertTrue(AuditLog::query()->where('action', 'guides.docs_locked_out')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'guides.docs_signed_in')->where('auditable_id', $this->staff->id)->exists());

        // Owner decision (2 Oct 2026): /docs guesses never count against the counter's per-sede PIN throttle.
        $unlock = new UnlockOperator;
        $this->assertFalse($unlock->isLockedOut('counter-pin:'.$this->sede->id));
        $this->assertSame($unlock->maxAttemptsAt($this->sede), $unlock->attemptsRemaining($this->sede, 'counter-pin:'.$this->sede->id));

        // Fifteen minutes later, the right PIN works again.
        $this->travel(16)->minutes();
        $this->assertNotNull($this->docsSignIn('3333')->getCookie(DocsAccess::COOKIE));
    }

    public function test_the_club_wide_ceiling_locks_every_ip(): void
    {
        foreach (range(1, DocsAccess::CLUB_CEILING) as $i) {
            $this->docsSignIn('8888', '10.2.'.intdiv($i, 250).'.'.($i % 250));
        }
        $this->assertNull($this->docsSignIn('3333', '172.16.0.1')->getCookie(DocsAccess::COOKIE));
        $this->assertTrue(AuditLog::query()->where('action', 'guides.docs_locked_out')->get()->contains(fn (AuditLog $log): bool => ($log->after['scope'] ?? null) === 'club'));
    }

    public function test_the_session_lasts_30_days_and_ends_on_deactivation_a_new_pin_salir_or_the_setting(): void
    {
        $response = $this->docsSignIn('3333');
        $cookie = $this->docsCookie($response);
        $expires = $response->getCookie(DocsAccess::COOKIE)->getExpiresTime();
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $expires, 120, 'a persistent 30-day cookie, so a browser restart keeps it');

        $index = fn () => $this->withCookie(DocsAccess::COOKIE, $cookie)->get(route('guides.docs'));
        $this->assertNotEmpty($this->listed($index()));

        $this->travel(29)->days();
        $this->assertNotEmpty($this->listed($index()), 'day 29: still in');
        $this->travel(2)->days();
        $this->assertEmpty($this->listed($index()), 'day 31: the PIN again');
        $this->travelBack();

        $this->staff->update(['pin' => '4444']);
        $this->assertEmpty($this->listed($index()), 'a new PIN ends it');

        $cookie = $this->docsCookie($this->docsSignIn('4444'));
        $this->staff->update(['active' => false]);
        $this->assertEmpty($this->listed($index()), 'deactivation ends it');
        $this->staff->update(['active' => true]);

        $this->assertNull($this->withCookie(DocsAccess::COOKIE, $cookie)->post(route('guides.docs.signout'))->getCookie(DocsAccess::COOKIE)?->getValue() ?: null, 'Salir forgets the cookie');

        Settings::set('guides_docs_enabled', false, SettingType::BOOL);
        $this->get(route('guides.docs'))->assertNotFound();
        $this->withCookie(DocsAccess::COOKIE, $cookie)->get(route('guides.docs.show', 'cash-at-the-counter'))->assertNotFound();
    }

    // --- 6. Keeping them current ---------------------------------------------------------------------------------------------

    public function test_every_guide_has_valid_front_matter_and_every_image_it_shows_exists(): void
    {
        $this->assertCount(4, GuideLibrary::all());
        $this->assertSame([], GuideLibrary::problems());
    }

    public function test_the_structural_check_catches_a_missing_image_and_missing_front_matter(): void
    {
        $root = sys_get_temp_dir().'/guides-'.uniqid();
        File::ensureDirectoryExists($root.'/en');
        File::put($root.'/en/planted.md', "## No front matter\n\n![Gone](img/planted/gone.jpg)\n");
        try {
            $problems = GuideLibrary::problems($root);
        } finally {
            File::deleteDirectory($root);
        }

        $this->assertContains('planted: front matter has no title', $problems);
        $this->assertContains('planted: image img/planted/gone.jpg is missing', $problems);
    }
}
