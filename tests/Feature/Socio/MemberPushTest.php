<?php

namespace Tests\Feature\Socio;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Announcement;
use App\Models\Event;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\PushSubscription;
use App\Notifications\EventReminderNotification;
use App\Notifications\LowBalanceNotification;
use App\Notifications\MembershipExpiringNotification;
use App\Notifications\NewAnnouncementNotification;
use App\Notifications\TemporaryAccessEndingNotification;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushMessage;
use Tests\TestCase;

class MemberPushTest extends TestCase
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
        // A configured VAPID keypair — the PUBLIC key is safe to expose; the private one never leaves the server.
        config(['webpush.vapid.public_key' => 'BPUBLICKEY', 'webpush.vapid.private_key' => 'PRIVATEKEY']);
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

    public function test_subscribe_stores_a_push_subscription_for_that_member_only(): void
    {
        $a = $this->member('a@example.es');
        $b = $this->member('b@example.es');

        $this->actingAs($a, 'member')->postJson(route('socio.push.subscribe'), [
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'the-public-key', 'auth' => 'the-auth'],
            'contentEncoding' => 'aes128gcm',
        ])->assertOk()->assertJson(['ok' => true]);

        $sub = PushSubscription::query()->where('endpoint', 'https://push.example/abc')->sole();
        $this->assertSame($a->id, $sub->subscribable_id);
        $this->assertSame(Member::class, $sub->subscribable_type);
        // It belongs to A, never to B: A has one, B has none.
        $this->assertNotSame($b->id, $sub->subscribable_id);
        $this->assertSame(1, $a->pushSubscriptions()->count());
        $this->assertSame(0, $b->pushSubscriptions()->count());
    }

    public function test_unsubscribe_removes_only_this_members_subscription(): void
    {
        $a = $this->member();
        PushSubscription::factory()->create([
            'subscribable_type' => Member::class, 'subscribable_id' => $a->id, 'endpoint' => 'https://push.example/keep-me',
        ]);

        $this->actingAs($a, 'member')->postJson(route('socio.push.unsubscribe'), [
            'endpoint' => 'https://push.example/keep-me',
        ])->assertOk();

        $this->assertSame(0, $a->pushSubscriptions()->count());
    }

    public function test_a_per_channel_opt_out_suppresses_that_channels_push(): void
    {
        Notification::fake();
        $announcement = Announcement::factory()->create(['organisation_id' => $this->org->id, 'published_at' => now()->subHour()]);

        // Opted IN (default) → the push is queued.
        $optedIn = $this->member('in@example.es');
        $optedIn->notify(new NewAnnouncementNotification($announcement));
        Notification::assertSentTo($optedIn, NewAnnouncementNotification::class);

        // Opted OUT of new_announcement → nothing is queued for that channel.
        $optedOut = $this->member('out@example.es');
        $optedOut->update(['push_opt_outs' => ['new_announcement']]);
        $optedOut->notify(new NewAnnouncementNotification($announcement));
        Notification::assertNotSentTo($optedOut, NewAnnouncementNotification::class);
    }

    public function test_no_push_is_attempted_when_vapid_is_unconfigured(): void
    {
        Notification::fake();
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);

        $announcement = Announcement::factory()->create(['organisation_id' => $this->org->id, 'published_at' => now()->subHour()]);
        $member = $this->member();
        $member->notify(new NewAnnouncementNotification($announcement));

        // Graceful degrade: no keys, so the channel is empty and nothing is sent.
        Notification::assertNotSentTo($member, NewAnnouncementNotification::class);
    }

    public function test_publishing_dispatches_to_in_scope_opted_in_members_only(): void
    {
        Notification::fake();

        $optedIn = $this->member('yes@example.es');
        $optedOut = $this->member('no@example.es');
        $optedOut->update(['push_opt_outs' => ['new_announcement']]);

        $announcement = Announcement::factory()->create(['organisation_id' => $this->org->id, 'location_id' => null, 'published_at' => now()->subHour()]);
        NewAnnouncementNotification::dispatchFor($announcement);

        Notification::assertSentTo($optedIn, NewAnnouncementNotification::class);
        Notification::assertNotSentTo($optedOut, NewAnnouncementNotification::class);
    }

    public function test_preferences_update_persists_opt_outs(): void
    {
        $member = $this->member();

        // The form submits the channels the member wants ON; the rest become opt-outs.
        $this->actingAs($member, 'member')->post(route('socio.notifications.prefs'), [
            'channels' => ['low_balance', 'membership_expiring', 'event_reminder'],
        ])->assertRedirect();

        $member->refresh();
        $this->assertContains('new_announcement', $member->pushOptOuts());
        $this->assertFalse($member->wantsPush('new_announcement'));
        $this->assertTrue($member->wantsPush('low_balance'));
    }

    public function test_every_push_channel_builds_a_message_and_respects_its_opt_out(): void
    {
        $member = $this->member();
        $announcement = Announcement::factory()->create(['organisation_id' => $this->org->id, 'published_at' => now()->subHour()]);
        $event = Event::factory()->create(['organisation_id' => $this->org->id]);

        $cases = [
            'low_balance' => new LowBalanceNotification(500),
            'membership_expiring' => new MembershipExpiringNotification('2026-12-31'),
            'new_announcement' => new NewAnnouncementNotification($announcement),
            'event_reminder' => new EventReminderNotification($event),
            'temporary_ending' => new TemporaryAccessEndingNotification('2026-12-31'),
        ];

        // Completeness: every declared push channel needs a render case here, so a new channel can't ship
        // without proof it builds a message and honours its opt-out.
        $this->assertEqualsCanonicalizing(Member::PUSH_CHANNELS, array_keys($cases), 'every push channel needs a render case');

        foreach ($cases as $channel => $notification) {
            // Opted in: the notification builds a real Web Push message and routes to the channel.
            $member->push_opt_outs = [];
            $this->assertNotEmpty($notification->via($member), "[$channel] should route when opted in");
            $this->assertInstanceOf(WebPushMessage::class, $notification->toWebPush($member, $notification), "[$channel] builds a message");

            // Opted out of THIS channel: it routes nowhere (nothing is sent).
            $member->push_opt_outs = [$channel];
            $this->assertSame([], $notification->via($member), "[$channel] must not route when opted out");
        }
    }

    public function test_the_notifications_page_exposes_the_public_but_never_the_private_vapid_key(): void
    {
        config(['webpush.vapid.public_key' => 'PUBLIC-VAPID-XYZ', 'webpush.vapid.private_key' => 'PRIVATE-VAPID-SECRET']);
        $member = $this->member();

        $response = $this->actingAs($member, 'member')->get(route('socio.notifications'));
        $response->assertOk();
        $response->assertSee('PUBLIC-VAPID-XYZ');
        $response->assertDontSee('PRIVATE-VAPID-SECRET');
    }
}
