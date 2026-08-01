<?php

namespace Tests\Feature\Localization;

use App\Actions\Governance\IssueConvocatoria;
use App\Actions\Members\SendMemberCard;
use App\Actions\ResolveLocale;
use App\Enums\ConvocatoriaType;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Mail\ConvocatoriaMail;
use App\Mail\MemberCardMail;
use App\Models\Convocatoria;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Prompt 96 — the member PWA rendered in English for a Spanish club because the resolver only looked at the
 * User guard, members had no locale column, and there was no switcher. Now: members carry a locale, the ONE
 * resolver reads it, a switcher applies it immediately, and queued mail resolves the recipient's language.
 */
class MemberLocaleTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function member(?string $locale, ?string $email = 'socio@example.es'): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'email' => $email, 'locale' => $locale,
            'status' => MemberStatus::ACTIVE, 'monthly_limit_cg' => 10000, 'daily_limit_cg' => 500,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    public function test_a_member_sees_the_pwa_in_their_own_locale(): void
    {
        $this->actingAs($this->member('es'), 'member')->get(route('socio.home'))
            ->assertOk()->assertSee('lang="es"', false);

        $this->actingAs($this->member('en', 'b@example.es'), 'member')->get(route('socio.home'))
            ->assertOk()->assertSee('lang="en"', false);
    }

    public function test_a_member_with_no_preference_falls_back_to_the_org_default_not_english(): void
    {
        // The shipped org default is Spanish (prompt 96), so a member with no preference gets es, not en.
        $noPref = $this->member(null);
        $this->assertSame('es', (new ResolveLocale)->handle($noPref));

        $this->actingAs($noPref, 'member')->get(route('socio.home'))->assertOk()->assertSee('lang="es"', false);

        // …and it is genuinely the ORG default, not hardcoded es: an org override to en is honoured.
        Settings::set('default_locale', 'en');
        $this->assertSame('en', (new ResolveLocale)->handle($this->member(null, 'c@example.es')));
    }

    public function test_the_switcher_changes_the_language_immediately_and_persists(): void
    {
        $member = $this->member('en');

        $this->actingAs($member, 'member')->post(route('socio.locale'), ['locale' => 'es'])->assertRedirect();

        $this->assertSame('es', $member->fresh()->locale);   // persisted to the member row
        $this->assertSame('es', session('locale'));           // mirrored to the session for the next request

        // Persists across a fresh session (the member row drives it, no override needed).
        $this->flushSession();
        $this->actingAs($member->fresh(), 'member')->get(route('socio.home'))->assertOk()->assertSee('lang="es"', false);
    }

    public function test_an_unknown_or_disabled_locale_on_a_member_degrades_gracefully(): void
    {
        $member = $this->member('xx'); // not an enabled locale

        // Falls through to the org default, never throws.
        $this->assertSame('es', (new ResolveLocale)->handle($member));
        $this->actingAs($member, 'member')->get(route('socio.home'))->assertOk()->assertSee('lang="es"', false);
    }

    public function test_the_qr_card_is_queued_in_the_members_resolved_locale(): void
    {
        Mail::fake();
        (new SendMemberCard)->handle($this->member('es'));

        Mail::assertQueued(MemberCardMail::class, fn (MemberCardMail $mail): bool => $mail->locale === 'es');
    }

    public function test_the_convocatoria_is_queued_in_each_members_resolved_locale(): void
    {
        Mail::fake();
        $this->member('es', 'es@example.es');
        $this->member('en', 'en@example.es');

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $convocatoria = Convocatoria::factory()->create([
            'organisation_id' => $this->org->id, 'type' => ConvocatoriaType::ORDINARY, 'held_at' => now()->addDays(30),
        ]);

        (new IssueConvocatoria)->handle($convocatoria, $owner);

        // A statutory notice must reach each member in THEIR language — resolved at issue, pinned on the job.
        Mail::assertQueued(ConvocatoriaMail::class, fn (ConvocatoriaMail $mail): bool => $mail->hasTo('es@example.es') && $mail->locale === 'es');
        Mail::assertQueued(ConvocatoriaMail::class, fn (ConvocatoriaMail $mail): bool => $mail->hasTo('en@example.es') && $mail->locale === 'en');
    }

    public function test_the_admin_user_locale_behaviour_is_unchanged(): void
    {
        // Regression: the shared resolver still honours a user's explicit preference over the org default.
        $this->assertSame('en', (new ResolveLocale)->handle(User::factory()->create(['locale' => 'en'])));
        $this->assertSame('es', (new ResolveLocale)->handle(User::factory()->create(['locale' => 'es'])));
    }
}
