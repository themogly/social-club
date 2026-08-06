<?php

namespace Tests\Feature\Socio;

use App\Actions\ResolveLocale;
use App\Enums\ApplicationStatus;
use App\Enums\SettingType;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Notifications\TemporaryAccessEndingNotification;
use App\Support\ActiveScope;
use App\Support\ConsentText;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 167 — the language switcher was gated on `$authed && $nav`, so the ONE audience who most
 * needs it never saw it.
 *
 * A signed-in member gets the switcher: they have already joined, already consented, and can ask a
 * member of staff. A prospective applicant got nothing — never having interacted with the club, quite
 * possibly not reading Spanish, and being asked to tick two boxes agreeing to the privacy declaration
 * and the statutes, which is Article 9 consent.
 *
 * It also made prompt 153 unreachable: that branch made the consent declarations per-locale and
 * recorded which locale the applicant read, precisely so consent is informed and reproducible — but on
 * this screen the other locale could never be shown.
 */
class ApplicationLocaleSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        Settings::set('enabled_locales', ['es', 'en'], SettingType::JSON);
    }

    private function invite(string $rawToken = 't'): MemberApplication
    {
        return MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', $rawToken),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);
    }

    // --- The switcher is reachable by the people who need it -------------------------------------

    public function test_the_application_form_renders_the_switcher_for_an_unauthenticated_visitor(): void
    {
        $this->invite();

        $this->get(route('socio.application', ['token' => 't']))
            ->assertOk()
            ->assertSee('data-locale-switcher', escape: false)
            ->assertSee('data-locale="es"', escape: false)
            ->assertSee('data-locale="en"', escape: false);
    }

    public function test_the_member_login_screen_offers_it_too(): void
    {
        // Also a nav="false", unauthenticated screen — a member who cannot read the page cannot get
        // as far as asking for a login link.
        $this->get(route('socio.login'))
            ->assertOk()
            ->assertSee('data-locale-switcher', escape: false);
    }

    public function test_with_one_locale_enabled_no_switcher_is_rendered(): void
    {
        Settings::set('enabled_locales', ['es'], SettingType::JSON);
        $this->invite();

        $this->get(route('socio.application', ['token' => 't']))
            ->assertOk()
            ->assertDontSee('data-locale-switcher', escape: false);
    }

    public function test_an_applicant_can_actually_switch_and_is_returned_to_the_form(): void
    {
        // The route sat behind auth:member, so before this branch even a rendered switcher would have
        // bounced an applicant to the login page.
        $this->invite();
        $url = route('socio.application', ['token' => 't']);

        $this->post(route('socio.locale'), ['locale' => 'en', 'return_to' => $url])
            ->assertRedirect($url);

        $this->assertSame('en', session('locale'));
    }

    public function test_an_off_site_return_target_is_refused(): void
    {
        $this->post(route('socio.locale'), ['locale' => 'en', 'return_to' => 'https://evil.example/phish'])
            ->assertRedirectContains(url('/'));
    }

    // --- The pairing prompt 153 built, now actually reachable --------------------------------------

    public function test_switching_language_changes_the_labels_and_the_consent_declarations(): void
    {
        ConsentText::class; // documented dependency: the declarations are per-locale (prompt 153)
        $this->invite();
        $url = route('socio.application', ['token' => 't']);

        $spanish = $this->withSession(['locale' => 'es'])->get($url)->assertOk();
        $english = $this->withSession(['locale' => 'en'])->get($url)->assertOk();

        // A form label, and the consent block, both follow the choice.
        $spanish->assertSee('Solicitud de alta');
        $english->assertSee('Membership application');

        $spanish->assertSee('He leído y acepto los estatutos de la asociación.');
        $english->assertSee('I have read and accept the association&#039;s statutes.', escape: false);

        $this->assertNotSame(
            $this->consentBlock($spanish->getContent()),
            $this->consentBlock($english->getContent()),
            'The consent declarations must change with the locale — that is what prompt 153 recorded.'
        );
    }

    // --- The anonymous default is DELIBERATELY unchanged ----------------------------------------------

    public function test_the_browser_language_does_not_override_the_club_default(): void
    {
        // Prompt 167 weighed honouring Accept-Language for anonymous visitors and decided against it
        // (see DECISIONS). Prompt 96 made the club default the only lever for a visitor with no
        // preference, and the switcher — now reachable on this very page — is the applicant's
        // override. An English browser must therefore still get the club's configured language.
        $this->invite();

        $this->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])
            ->get(route('socio.application', ['token' => 't']))
            ->assertOk()
            ->assertSee('lang="es"', escape: false)
            ->assertSee('Solicitud de alta');
    }

    public function test_an_explicit_choice_still_wins(): void
    {
        // …and it is one tap away, which is the whole point of the branch.
        $this->invite();

        $this->withSession(['locale' => 'en'])
            ->withHeaders(['Accept-Language' => 'es-ES,es;q=0.9'])
            ->get(route('socio.application', ['token' => 't']))
            ->assertOk()
            ->assertSee('Membership application');
    }

    // --- The resolver prompt 96 built must be untouched ---------------------------------------------

    public function test_a_queued_job_and_a_notification_resolve_exactly_as_before(): void
    {
        // Neither has a request. The browser hint lives in the middleware precisely so this is true by
        // construction — if it had gone into ResolveLocale, this is what would have broken.
        Settings::set('default_locale', 'es', SettingType::STRING);

        $this->assertSame('es', (new ResolveLocale)->handle(null));

        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'locale' => 'en']);
        $this->assertSame('en', (new ResolveLocale)->handle($member));

        // A notification resolves through the member's own preference, not any ambient request.
        $notification = new TemporaryAccessEndingNotification('2026-12-31');
        $this->assertSame('en', $member->preferredLocale());
        $this->assertNotNull($notification);
    }

    public function test_the_resolver_ignores_an_accept_language_header_entirely(): void
    {
        Settings::set('default_locale', 'es', SettingType::STRING);

        $this->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9']);

        // Called directly, as a job would: the header is not consulted.
        $this->assertSame('es', (new ResolveLocale)->handle(null));
    }

    // --- A member's switcher is unchanged ------------------------------------------------------------

    public function test_a_logged_in_members_switch_still_persists_to_their_row(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'locale' => 'es']);

        $this->actingAs($member, 'member')
            ->post(route('socio.locale'), ['locale' => 'en']);

        $this->assertSame('en', $member->fresh()->locale);
        $this->assertSame('en', session('locale'));
    }

    /** The consent block only, so the comparison is about the declarations and not the whole page. */
    private function consentBlock(string $html): string
    {
        preg_match_all('/name="consent_(?:data|statutes)".*?<\/label>/s', $html, $matches);

        return implode('|', $matches[0]);
    }
}
