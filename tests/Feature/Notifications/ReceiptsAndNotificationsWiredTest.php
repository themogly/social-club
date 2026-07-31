<?php

namespace Tests\Feature\Notifications;

use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\EventRsvpStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Livewire\Counter\DispensaryPos;
use App\Mail\DispensationReceiptMail;
use App\Models\Dispensation;
use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use App\Notifications\LowBalanceNotification;
use App\Notifications\MembershipExpiringNotification;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 56 — the three notifications built with zero dispatch sites are now wired, and receipts are
 * emailable. Membership → the existing sweep (same reminder marker); events → a nightly sweep with a
 * per-event marker; low balance → RecordWalletTransaction on the crossing; receipt → a POS action + a
 * mailable (the render/CID assertion rides on the existing MailRenderTest via DevMail).
 */
class ReceiptsAndNotificationsWiredTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private MembershipTier $tier;

    protected function setUp(): void
    {
        parent::setUp();
        // Push is VAPID-gated: without keys the notifications' via() returns [] and nothing sends.
        config(['webpush.vapid.public_key' => 'BPUBLICKEY', 'webpush.vapid.private_key' => 'PRIVATEKEY']);
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
    }

    public function test_the_membership_sweep_also_pushes_the_expiring_notification(): void
    {
        Mail::fake();
        Notification::fake();
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'email' => 'm@example.es']);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $this->tier->id, 'status' => MembershipStatus::ACTIVE, 'expires_at' => now()->addDays(3),
        ]);

        $this->artisan('memberships:sweep')->assertSuccessful();

        Notification::assertSentTo($member, MembershipExpiringNotification::class);
    }

    public function test_events_remind_dispatches_once_and_is_idempotent(): void
    {
        Notification::fake();
        $event = Event::factory()->create(['organisation_id' => $this->org->id, 'starts_at' => now()->addHours(12)]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        EventRsvp::factory()->create(['event_id' => $event->id, 'member_id' => $member->id, 'status' => EventRsvpStatus::GOING]);

        $this->artisan('events:remind')->assertSuccessful();
        Notification::assertSentTo($member, EventReminderNotification::class);
        $this->assertNotNull($event->fresh()->reminder_sent_at);

        // Second run: the per-event marker makes it a no-op.
        Notification::fake();
        $this->artisan('events:remind')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_a_debit_crossing_the_low_balance_threshold_pushes_once(): void
    {
        Notification::fake();
        Settings::set('low_balance_threshold_cents', 500, SettingType::CENTS);
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL);
        Settings::set('wallet_debt_limit_cents', 10000, SettingType::CENTS);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $recorder = new RecordWalletTransaction;

        $recorder->handle($member, $this->location, 1000, WalletTransactionType::TOPUP);          // €10, above
        $recorder->handle($member, $this->location, -700, WalletTransactionType::CONTRIBUTION);    // €3, crosses below
        Notification::assertSentTo($member, LowBalanceNotification::class);

        // Already below → a further debit does NOT re-push.
        Notification::fake();
        $recorder->handle($member, $this->location, -100, WalletTransactionType::CONTRIBUTION);
        Notification::assertNothingSent();
    }

    public function test_the_dispensary_pos_emails_the_receipt_to_the_member(): void
    {
        Mail::fake();
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'email' => 'socio@example.es', 'status' => MemberStatus::ACTIVE,
        ]);
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'total_cents' => 2625, 'cash_cents' => 2625, 'wallet_cents' => 0,
        ]);

        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        Livewire::test(DispensaryPos::class)
            ->set('lastDispensationId', $dispensation->id)
            ->call('emailReceipt');

        Mail::assertSent(DispensationReceiptMail::class, fn (DispensationReceiptMail $mail): bool => $mail->hasTo('socio@example.es'));
    }
}
