<?php

namespace Tests\Feature\Socio;

use App\Enums\EventRsvpStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Announcement;
use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberCommsTest extends TestCase
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
            'organisation_id' => $this->org->id, 'email' => $email, 'status' => MemberStatus::ACTIVE,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    public function test_comms_routes_redirect_when_unauthenticated(): void
    {
        foreach (['socio.announcements', 'socio.events', 'socio.notifications'] as $name) {
            $this->get(route($name))->assertRedirect(route('socio.login'));
        }

        $event = Event::factory()->create(['organisation_id' => $this->org->id]);
        $this->post(route('socio.events.rsvp', ['event' => $event->id]))->assertRedirect(route('socio.login'));
    }

    public function test_member_sees_only_published_in_scope_announcements(): void
    {
        $member = $this->member();
        $otherLocation = Location::factory()->create(['organisation_id' => $this->org->id]);

        Announcement::factory()->create(['organisation_id' => $this->org->id, 'location_id' => null, 'title' => 'AVISO-ORG', 'published_at' => now()->subHour()]);
        Announcement::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'title' => 'AVISO-MI-SEDE', 'published_at' => now()->subHour()]);
        Announcement::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $otherLocation->id, 'title' => 'AVISO-OTRA-SEDE', 'published_at' => now()->subHour()]);
        Announcement::factory()->create(['organisation_id' => $this->org->id, 'location_id' => null, 'title' => 'AVISO-BORRADOR', 'published_at' => null]);
        Announcement::factory()->create(['organisation_id' => $this->org->id, 'location_id' => null, 'title' => 'AVISO-FUTURO', 'published_at' => now()->addDay()]);
        Announcement::factory()->create(['organisation_id' => $this->org->id, 'location_id' => null, 'title' => 'AVISO-CADUCADO', 'published_at' => now()->subDays(3), 'expires_at' => now()->subDay()]);

        $response = $this->actingAs($member, 'member')->get(route('socio.announcements'));
        $response->assertOk();
        $response->assertSee('AVISO-ORG');
        $response->assertSee('AVISO-MI-SEDE');
        $response->assertDontSee('AVISO-OTRA-SEDE');
        $response->assertDontSee('AVISO-BORRADOR');
        $response->assertDontSee('AVISO-FUTURO');
        $response->assertDontSee('AVISO-CADUCADO');
    }

    public function test_rsvp_is_scoped_to_the_member_and_toggles(): void
    {
        $member = $this->member();
        $event = Event::factory()->create(['organisation_id' => $this->org->id, 'location_id' => null, 'capacity' => 50]);

        // First tap: the member is GOING — the RSVP is stored against THEM.
        $this->actingAs($member, 'member')->post(route('socio.events.rsvp', ['event' => $event->id]))
            ->assertRedirect();

        $rsvp = EventRsvp::query()->where('event_id', $event->id)->sole();
        $this->assertSame($member->id, $rsvp->member_id);
        $this->assertSame(EventRsvpStatus::GOING, $rsvp->status);

        // Second tap toggles them back off — still exactly one row, now declined.
        $this->actingAs($member, 'member')->post(route('socio.events.rsvp', ['event' => $event->id]));
        $this->assertSame(1, EventRsvp::query()->where('event_id', $event->id)->count());
        $this->assertSame(EventRsvpStatus::DECLINED, EventRsvp::query()->where('event_id', $event->id)->sole()->status);
    }

    public function test_rsvp_is_capacity_checked(): void
    {
        $member = $this->member('me@example.es');
        $filler = $this->member('filler@example.es');
        $event = Event::factory()->create(['organisation_id' => $this->org->id, 'location_id' => null, 'capacity' => 1]);

        EventRsvp::factory()->create(['event_id' => $event->id, 'member_id' => $filler->id, 'status' => EventRsvpStatus::GOING]);

        $this->actingAs($member, 'member')->post(route('socio.events.rsvp', ['event' => $event->id]))
            ->assertSessionHasErrors('rsvp');

        // The full event gained no new confirmed attendee.
        $this->assertSame(1, EventRsvp::query()->where('event_id', $event->id)->where('status', EventRsvpStatus::GOING->value)->count());
        $this->assertNull(EventRsvp::query()->where('event_id', $event->id)->where('member_id', $member->id)->first());
    }

    public function test_a_member_cannot_rsvp_to_an_out_of_scope_event(): void
    {
        $member = $this->member();

        // Same org, a sede the member does not belong to → forbidden.
        $otherLocation = Location::factory()->create(['organisation_id' => $this->org->id]);
        $foreignLocationEvent = Event::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $otherLocation->id]);
        $this->actingAs($member, 'member')->post(route('socio.events.rsvp', ['event' => $foreignLocationEvent->id]))
            ->assertForbidden();

        // A different organisation entirely → not found.
        $otherOrg = Organisation::factory()->create();
        $foreignOrgEvent = Event::factory()->create(['organisation_id' => $otherOrg->id, 'location_id' => null]);
        $this->actingAs($member, 'member')->post(route('socio.events.rsvp', ['event' => $foreignOrgEvent->id]))
            ->assertNotFound();

        $this->assertSame(0, EventRsvp::query()->where('member_id', $member->id)->count());
    }
}
