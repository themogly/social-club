<?php

namespace Tests\Feature\Mail;

use App\Actions\Lockdown\InitiateLockdown;
use App\Actions\MemberAuth\IssueMemberLoginLink;
use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Mail\ApplicationApprovedMail;
use App\Mail\ApplicationRejectedMail;
use App\Mail\ClubMail;
use App\Mail\LockdownReactivationMail;
use App\Mail\MemberCardMail;
use App\Mail\MemberLoginLinkMail;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 288 — every email the system promises, actually sent: the approved email on EVERY approval (not only the
 * panel's), in the recipient's own language, retried when the transport hiccups, visible when it is finally lost, the
 * owner's lockdown way-back only after the lockdown exists, and a way to tell whether mail works at all.
 */
class EveryEmailSentTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->owner = User::factory()->create(['email' => 'owner@club.test']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->location->id]);
    }

    /** @param array<string, mixed> $payload */
    private function submitted(array $payload = []): MemberApplication
    {
        return MemberApplication::factory()->submitted()->create([
            'organisation_id' => $this->org->id,
            'location_id' => $this->location->id,
            'status' => ApplicationStatus::PENDING,
            'payload' => array_merge([
                'first_name' => 'María', 'last_name' => 'García', 'email' => 'maria@example.test',
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'document_type' => 'DNI', 'document_number' => '12345678Z',
                'consents' => ['membership', 'data_processing'],
            ], $payload),
        ]);
    }

    /** @return list<class-string> the mailables queued so far, in order */
    private function queuedInOrder(): array
    {
        return array_map(fn (object $mail): string => $mail::class, (fn (): array => $this->queuedMailables)->call(Mail::getFacadeRoot()));
    }

    // 2 -------------------------------------------------------------------------------------------------------------

    public function test_approving_in_the_panel_sends_approved_then_card_and_nothing_twice(): void
    {
        Mail::fake();
        $application = $this->submitted();

        Livewire::actingAs($this->owner)->test(ListMemberApplications::class)
            ->callTableAction('approve', $application, ['allow_duplicate' => false])
            ->assertHasNoTableActionErrors();

        $this->assertSame([ApplicationApprovedMail::class, MemberCardMail::class], $this->queuedInOrder());
    }

    // 3 -------------------------------------------------------------------------------------------------------------

    public function test_the_approved_email_the_login_link_and_the_rejection_go_out_in_the_recipients_language(): void
    {
        Mail::fake();

        $application = $this->submitted(['consent_locale' => 'en']);
        Livewire::actingAs($this->owner)->test(ListMemberApplications::class)
            ->callTableAction('approve', $application, ['allow_duplicate' => false]);
        Mail::assertQueued(ApplicationApprovedMail::class, fn (ApplicationApprovedMail $mail): bool => $mail->locale === 'en');

        $member = Member::query()->withoutGlobalScopes()->sole();
        $this->assertSame('en', $member->locale, 'the member keeps the language they applied in');
        $member->forceFill(['status' => MemberStatus::ACTIVE])->saveQuietly();
        (new IssueMemberLoginLink)->handle('maria@example.test');
        Mail::assertQueued(MemberLoginLinkMail::class, fn (MemberLoginLinkMail $mail): bool => $mail->locale === 'en');

        $rejected = $this->submitted(['email' => 'john@example.test', 'first_name' => 'John', 'consent_locale' => 'en']);
        Livewire::actingAs($this->owner)->test(ListMemberApplications::class)
            ->callTableAction('reject', $rejected, ['reason' => 'Aval no válido']);
        Mail::assertQueued(ApplicationRejectedMail::class, fn (ApplicationRejectedMail $mail): bool => $mail->hasTo('john@example.test') && $mail->locale === 'en');
    }

    // 5 -------------------------------------------------------------------------------------------------------------

    public function test_a_mail_that_finally_fails_is_recorded_without_its_address(): void
    {
        $mail = new ApplicationApprovedMail('María García', 'S-00001');
        $mail->to('maria@example.test');

        $mail->failed(new RuntimeException('Resend 429'));

        $entry = AuditLog::query()->where('action', 'mail.failed')->sole();
        $this->assertSame(ApplicationApprovedMail::class, data_get($entry->after, 'mailable'));
        $this->assertStringNotContainsString('maria@example.test', (string) json_encode([$entry->before, $entry->after]));
    }

    // 6 -------------------------------------------------------------------------------------------------------------

    public function test_the_lockdown_way_back_is_queued_after_the_commit_and_never_on_a_rollback(): void
    {
        config(['queue.default' => 'database']);

        try {
            DB::transaction(function (): void {
                (new InitiateLockdown)->handle($this->org, ['actor' => $this->owner]);
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, DB::table('jobs')->count(), 'a rolled-back lockdown must not email a way back');

        (new InitiateLockdown)->handle($this->org, ['actor' => $this->owner]);
        $jobs = DB::table('jobs')->pluck('payload');
        $this->assertCount(1, $jobs);
        $this->assertStringContainsString(str_replace('\\', '\\\\', LockdownReactivationMail::class), (string) $jobs[0]);
        $this->assertTrue(is_subclass_of(LockdownReactivationMail::class, ClubMail::class));
    }

    public function test_the_lockdown_mail_is_in_the_owners_language(): void
    {
        Mail::fake();
        $this->owner->forceFill(['locale' => 'en'])->save();

        (new InitiateLockdown)->handle($this->org, ['actor' => $this->owner]);

        Mail::assertQueued(LockdownReactivationMail::class, fn (LockdownReactivationMail $mail): bool => $mail->locale === 'en');
    }

    // 7 -------------------------------------------------------------------------------------------------------------

    public function test_the_password_reset_link_points_at_app_url(): void
    {
        Notification::fake();
        $appUrl = rtrim((string) config('app.url'), '/'); // the URL root is fixed at boot from APP_URL

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'Owner@Club.TEST'])
            ->call('request')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($this->owner, ResetPassword::class, fn (ResetPassword $n): bool => str_starts_with($n->url, $appUrl.'/password-reset/reset?email=owner%40club.test'));
    }

    public function test_a_password_reset_that_cannot_be_queued_reads_as_a_message_not_a_500(): void
    {
        Notification::shouldReceive('send')->andThrow(new RuntimeException('queue down'));

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'owner@club.test'])
            ->call('request')
            ->assertNotified(__('No se pudo enviar el correo ahora mismo. Inténtalo de nuevo en unos minutos.'));
    }

    // 8 -------------------------------------------------------------------------------------------------------------

    public function test_the_mail_test_command_reports_sent_or_the_transport_error(): void
    {
        config(['mail.default' => 'array']);
        $this->assertSame(0, Artisan::call('csc:mail-test', ['email' => 'probe@example.test']));
        $this->assertStringContainsString('Enviado', Artisan::output());

        Mail::shouldReceive('raw')->andThrow(new RuntimeException('Expected response code 250 but got 535'));
        $this->assertSame(1, Artisan::call('csc:mail-test', ['email' => 'probe@example.test']));
        $this->assertStringContainsString('535', Artisan::output());
        $this->assertSame(0, AuditLog::query()->where('after', 'like', '%probe@example.test%')->count());
    }

    // 9 -------------------------------------------------------------------------------------------------------------

    public function test_the_health_page_marks_correo_red_without_a_resend_key_and_green_with_one(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => null, 'mail.from.address' => 'hola@club.es']);
        $this->assertSame('red', (new SystemHealth)->mailer()['status']);

        config(['services.resend.key' => 're_live_123']);
        $this->assertSame('green', (new SystemHealth)->mailer()['status']);

        config(['mail.from.address' => 'hello@example.com']);
        $this->assertSame('amber', (new SystemHealth)->mailer()['status']);

        $this->app['env'] = 'production';
        config(['mail.default' => 'log']);
        $this->assertSame('red', (new SystemHealth)->mailer()['status']);
    }

    public function test_failed_mail_jobs_of_the_last_week_are_counted_by_mailable(): void
    {
        DB::table('failed_jobs')->insert([
            ['uuid' => 'a', 'connection' => 'redis', 'queue' => 'default', 'exception' => 'x', 'failed_at' => now()->subDay(),
                'payload' => json_encode(['displayName' => MemberCardMail::class])],
            ['uuid' => 'b', 'connection' => 'redis', 'queue' => 'default', 'exception' => 'x', 'failed_at' => now()->subDays(10),
                'payload' => json_encode(['displayName' => MemberCardMail::class])],
            ['uuid' => 'c', 'connection' => 'redis', 'queue' => 'default', 'exception' => 'x', 'failed_at' => now()->subHour(),
                'payload' => json_encode(['displayName' => 'App\\Jobs\\SomethingElse'])],
        ]);

        $this->assertSame([MemberCardMail::class => 1], (new SystemHealth)->mailer()['failed_last_7_days']);
    }
}
