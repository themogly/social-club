<?php

namespace Tests\Feature\Socio;

use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\MemberAuth\ConsumeMemberLoginToken;
use App\Actions\MemberAuth\IssueMemberLoginLink;
use App\Actions\Members\IssueMemberToken;
use App\Actions\Members\ResolveMemberByToken;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Mail\MemberLoginLinkMail;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberLoginToken;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Weight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MemberPwaTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function member(string $email = 'socio@example.es'): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'email' => $email,
            'status' => MemberStatus::ACTIVE, 'monthly_limit_cg' => 10000, 'daily_limit_cg' => 500,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    public function test_every_member_route_redirects_to_the_member_login_when_unauthenticated(): void
    {
        foreach (['socio.home', 'socio.menu', 'socio.history', 'socio.export'] as $name) {
            $this->get(route($name))->assertRedirect(route('socio.login'));
        }
    }

    public function test_a_member_only_ever_sees_their_own_data(): void
    {
        $a = $this->member('a@example.es');
        $b = $this->member('b@example.es');

        // No member id in any route — A is scoped to A. The export proves it.
        $response = $this->actingAs($a, 'member')->get(route('socio.export'));
        $response->assertOk();
        $response->assertSee($a->member_no);
        $response->assertDontSee($b->member_no);
    }

    public function test_the_pwa_qr_token_resolves_at_checkin_and_a_revoked_one_fails(): void
    {
        $member = $this->member();

        $first = (new IssueMemberToken)->handle($member);
        $this->assertSame($member->id, (new ResolveMemberByToken)->handle($first)?->id);

        // Issuing again (as the PWA home does) rotates the card and revokes the old one.
        $second = (new IssueMemberToken)->handle($member);
        $this->assertNull((new ResolveMemberByToken)->handle($first));               // revoked → fails
        $this->assertSame($member->id, (new ResolveMemberByToken)->handle($second)?->id);
    }

    public function test_the_allowance_shown_matches_the_resolver_exactly(): void
    {
        $member = $this->member();
        $snapshot = (new ResolveMemberLimits)->handle($member, $this->location);

        $response = $this->actingAs($member, 'member')->get(route('socio.home'));
        $response->assertOk();
        // The home renders the resolver's own figure — no second arithmetic.
        $response->assertSee(Weight::fromCentigrams($snapshot->monthlyLimitCg)->formatted());
    }

    public function test_a_magic_link_token_is_single_use_and_expires(): void
    {
        $member = $this->member();
        $raw = 'raw-token-value';
        MemberLoginToken::create([
            'member_id' => $member->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(15),
        ]);

        // First use resolves the member; a second use is refused (single-use).
        $this->assertSame($member->id, (new ConsumeMemberLoginToken)->handle($raw)?->id);
        $this->assertNull((new ConsumeMemberLoginToken)->handle($raw));

        // An expired token never resolves.
        $expired = 'expired-token';
        MemberLoginToken::create([
            'member_id' => $member->id, 'token_hash' => hash('sha256', $expired), 'expires_at' => now()->subMinute(),
        ]);
        $this->assertNull((new ConsumeMemberLoginToken)->handle($expired));
    }

    public function test_the_full_magic_link_flow_logs_the_member_in(): void
    {
        Mail::fake();
        $member = $this->member();

        (new IssueMemberLoginLink)->handle($member->email);
        Mail::assertQueued(MemberLoginLinkMail::class);

        // Reconstruct the link the member would click (raw token off the queued mailable).
        $token = null;
        Mail::assertQueued(MemberLoginLinkMail::class, function (MemberLoginLinkMail $mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        $this->get(route('socio.login.verify', ['token' => $token]))->assertRedirect(route('socio.home'));
        $this->assertAuthenticatedAs($member, 'member');
    }

    public function test_the_login_request_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('socio.login.send'), ['email' => 'x@example.es'])->assertStatus(302);
        }
        $this->post(route('socio.login.send'), ['email' => 'x@example.es'])->assertStatus(429);
    }

    public function test_member_surfaces_are_served_noindex(): void
    {
        $this->get(route('socio.login'))->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
