<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 249 — the handed-over tablet has a way back.
 *
 * The tester, on the tablet: the sign-up thank-you screen locked the app to itself — every URL loaded the
 * applicant's form back. The surface with the PIN pad existed (173/187); nothing on the screen where the
 * tablet is handed back led to it.
 *
 * These drive the REAL entries — `handOverForAlta` (which records the return URL, so the boundary can be
 * measured — `beginHandover()` with no URL cannot) and a real `POST` to the tokenised form (so a
 * silently-dropped submit cannot pass the chain for the wrong reason).
 *
 * NOTE on the session: the test env uses `SESSION_DRIVER=array`, so a real HTTP POST does not carry its
 * session into a *later* full-page GET or `Livewire::test` (production keeps one cookie-backed session
 * throughout; the browser harness is the real end-to-end proof). So after the real POST we CAPTURE the
 * handover session and re-seed it on the requests that follow — exactly the state production would carry.
 */
class HandoverReturnTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);

        // One device account with a PIN — it hands over, and its PIN brings the counter back.
        $this->operator = User::factory()->create(['name' => 'Marta Operadora', 'pin' => Hash::make('4321')]);
        $this->operator->assignRole(Role::OWNER->value);
        $this->operator->locations()->sync([$this->location->id]);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    /** Hand the tablet over through 174's real entry, which records the return URL. Returns the application. */
    private function handOverForAlta(): MemberApplication
    {
        $this->actingAs($this->operator);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($this->operator);

        Livewire::actingAs($this->operator)->test(MembershipCounter::class)->call('handOverForAlta');
        $this->assertTrue(CounterHandover::active(), 'precondition: the tablet is handed over');

        return MemberApplication::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** Submit the tokenised form with a real POST; return the handover session as it stands afterwards. */
    private function submit(string $token, array $overrides = []): array
    {
        $this->post(route('socio.application.store', ['token' => $token]), $this->formData($overrides));

        return (array) session('counter.handover');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function formData(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.es',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI', 'document_number' => '12345678Z',
            'declared_monthly_g' => '30', 'consent_data' => '1', 'consent_statutes' => '1',
            'signature' => 'data:image/png;base64,'.base64_encode('sig'),
            ApplicationSpamGuard::HONEYPOT => '',
            ApplicationSpamGuard::TIMESTAMP => $this->agedToken(ApplicationSpamGuard::MIN_SECONDS + 2),
        ], $overrides);
    }

    private function agedToken(int $ageSeconds): string
    {
        $this->travelTo(now()->subSeconds($ageSeconds));
        $token = ApplicationSpamGuard::issueToken();
        $this->travelBack();

        return $token;
    }

    // --- A: /counter answers during a handover ----------------------------------

    public function test_the_front_door_answers_with_the_surface_during_a_handover(): void
    {
        $this->handOverForAlta();

        // 189's front door was added after the allowlist was written and forgotten — it redirected instead of
        // rendering, though it renders only the surface. It is the screen most likely to be typed.
        $html = $this->get(route('counter.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-surface-mode="handover"', $html);
        $this->assertStringNotContainsString('data-counter-topbar', $html);
    }

    // --- A: after submission a stray to the dashboard lands on the pad, not the form ----

    public function test_after_submission_a_stray_to_the_dashboard_lands_on_the_pad(): void
    {
        $application = $this->handOverForAlta();
        $handover = $this->submit($application->invite_token);

        // The submit landed (guarding false-green #14: a dropped submit renders the same thank-you).
        $this->assertNotNull($application->fresh()->submitted_at, 'the submit was dropped');
        $this->assertTrue(CounterHandover::submitted(), 'the handover was not marked submitted');
        $this->assertNull(CounterHandover::returnUrl(), 'a finished form is nowhere to send anyone back to');

        // The dashboard is NOT allowlisted; with returnUrl() now null the boundary lands the stray on the PIN
        // pad, never the finished form. (The panel runs its own StartSession, so the handover is seeded for it.)
        $this->withSession(['counter.handover' => $handover])->get('/')->assertRedirect(route('counter.checkin'));
    }

    // --- A: a submitted form shows the received state, never the payload --------

    public function test_the_invite_url_after_submission_hides_the_form_and_the_payload(): void
    {
        // No handover — the plain emailed-invite path — so the received state (from submitted_at) is the point.
        $application = MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', 'tok-abc'),
            'status' => ApplicationStatus::PENDING, 'payload' => [],
        ]);

        $this->submit('tok-abc');
        $application->refresh();
        $this->assertNotNull($application->submitted_at);
        $submittedAt = $application->submitted_at;

        $html = $this->get(route('socio.application', ['token' => 'tok-abc']))->assertOk()->getContent();

        $this->assertStringNotContainsString('action="'.route('socio.application.store', ['token' => 'tok-abc']).'"', $html, 'the form is still on the page');
        $this->assertStringNotContainsString('12345678Z', $html, 'the document number is on the page');
        $this->assertStringNotContainsString('García', $html, 'the surname is on the page');

        // A second POST changes nothing.
        $this->submit('tok-abc', ['document_number' => '99999999X']);
        $application->refresh();
        $this->assertSame('12345678Z', $application->payload['document_number'] ?? null, 'a second POST rewrote the payload');
        $this->assertEquals($submittedAt, $application->submitted_at, 'a second POST moved submitted_at');
    }

    // --- B: the thank-you card carries the way back, only in a handover ---------

    public function test_the_thank_you_card_carries_the_return_button_only_in_a_handover(): void
    {
        $application = $this->handOverForAlta();
        $this->submit($application->invite_token);

        $html = $this->get(route('socio.application', ['token' => $application->invite_token]))->assertOk()->getContent();
        $this->assertStringContainsString('data-handover-return', $html);
        $this->assertStringContainsString(route('counter.checkin'), $html);
        $this->assertStringContainsString(__('Devolver la tablet al personal'), $html);

        // The same received card, no handover: no button.
        $emailed = MemberApplication::factory()->create([
            'organisation_id' => $this->org->id, 'invite_token_hash' => hash('sha256', 'tok-email'),
            'status' => ApplicationStatus::PENDING, 'payload' => [],
        ]);
        CounterHandover::end();
        $this->submit('tok-email');
        $this->assertNotNull($emailed->fresh()->submitted_at);

        $emailedHtml = $this->get(route('socio.application', ['token' => 'tok-email']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-handover-return', $emailedHtml);
    }

    // --- B: the surface, submitted — pad open, "Solicitud recibida" -------------

    public function test_the_submitted_surface_opens_the_pad_and_says_received(): void
    {
        $application = $this->handOverForAlta();
        $this->submit($application->invite_token);

        // Both the working screen AND the allowlisted front door render the submitted surface (the pad).
        foreach ([route('counter.checkin'), route('counter.home')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(__('Solicitud recibida'), $html, "no 'received' on $url");
            $this->assertStringContainsString('data-counter-surface-unlock', $html, "the pad is not on $url");
            // There is no applicant screen left to return to, so the cancel control is not rendered.
            $this->assertStringNotContainsString('data-handover-staff-cancel', $html);
        }
    }

    // --- C: the PIN lands on the scoped review ----------------------------------

    public function test_the_whole_lifecycle_ends_on_the_review_with_the_applicants_name(): void
    {
        $application = $this->handOverForAlta();
        $this->submit($application->invite_token);
        $this->assertNotNull($application->fresh()->submitted_at, 'the submit was dropped — the rest would pass for the wrong reason');

        // The HTTP POST ran on the harness's throwaway session (SESSION_DRIVER=array); production keeps one
        // cookie-backed session throughout. Re-establish the SUBMITTED handover in-process — exactly the state
        // the real POST left in production — so the Livewire PIN step reads it (Livewire→Livewire persists).
        CounterHandover::begin((string) $this->operator->id, $this->location->id, $application->inviteUrl());
        CounterHandover::markSubmitted($application->id);
        session(['counter.location_id' => $this->location->id]);

        // The PIN from the submitted surface ends the handover and redirects to the review.
        Livewire::actingAs($this->operator)->test(CheckInScreen::class)
            ->set('operatorPin', '4321')
            ->call('unlockOperator')
            ->assertRedirect(route('counter.members', ['alta' => $application->id]));

        $this->assertFalse(CounterHandover::active(), 'the handover did not end');

        // The review, reached from that redirect (?alta=…), shows the applicant and Aprobar.
        CounterOperator::set($this->operator);
        $review = Livewire::actingAs($this->operator)->withQueryParams(['alta' => $application->id])->test(MembershipCounter::class)
            ->assertSet('altaApplicationId', $application->id)
            ->assertSet('altaOpen', true);

        $html = $review->html();
        $this->assertStringContainsString('María', $html);
        $this->assertStringContainsString('data-alta-approve', $html);
    }

    // --- C: the review is scoped to the counter's organisation (the IDOR fix) ---

    public function test_a_foreign_organisations_application_opens_nothing(): void
    {
        $this->actingAs($this->operator);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($this->operator);

        $otherOrg = Organisation::factory()->create();
        $foreign = MemberApplication::factory()->create([
            'organisation_id' => $otherOrg->id, 'status' => ApplicationStatus::PENDING,
            'submitted_at' => now(), 'payload' => ['first_name' => 'Ajeno', 'last_name' => 'Secreto'],
        ]);

        // reviewAltaApplication() resolves nothing of it — altaApplication() is org-scoped.
        $bySetter = Livewire::actingAs($this->operator)->test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('reviewAltaApplication', $foreign->id);
        $this->assertStringNotContainsString('Secreto', $bySetter->html());

        // The URL entry (?alta=) opens nothing at all for a foreign id.
        Livewire::actingAs($this->operator)->withQueryParams(['alta' => $foreign->id])->test(MembershipCounter::class)
            ->assertSet('altaOpen', false)
            ->assertSet('altaApplicationId', null);

        // An own-org submitted application DOES open, with the applicant's name.
        $own = MemberApplication::factory()->create([
            'organisation_id' => $this->org->id, 'status' => ApplicationStatus::PENDING,
            'submitted_at' => now(), 'payload' => ['first_name' => 'Propia', 'last_name' => 'Socia'],
        ]);
        $ownReview = Livewire::actingAs($this->operator)->withQueryParams(['alta' => $own->id])->test(MembershipCounter::class)
            ->assertSet('altaOpen', true)
            ->assertSet('altaApplicationId', $own->id);
        $this->assertStringContainsString('Propia', $ownReview->html());
    }
}
