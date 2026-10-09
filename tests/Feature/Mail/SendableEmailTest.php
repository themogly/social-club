<?php

namespace Tests\Feature\Mail;

use App\Actions\Alerts\SendMorningSummaries;
use App\Actions\Governance\IssueConvocatoria;
use App\Actions\MemberAuth\IssueMemberLoginLink;
use App\Actions\Members\ApproveApplication;
use App\Actions\Members\ImportMembers;
use App\Actions\Members\SendMemberCard;
use App\Enums\AlertType;
use App\Enums\ApplicationStatus;
use App\Enums\ConvocatoriaRecipientStatus;
use App\Enums\ConvocatoriaType;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Livewire\Counter\DispensaryPos;
use App\Mail\AlertSummaryMail;
use App\Mail\MemberCardMail;
use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Models\User;
use App\Rules\SendableEmail;
use App\Support\ActiveScope;
use App\Support\ApplicationShape;
use App\Support\CounterOperator;
use App\Support\Email;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Resend\Exceptions\ErrorException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Prompt 372 — Sentry, 8 October, a Horizon worker: «Request to Resend API failed. Reason: Invalid `to` field.» A member's
 * email with a space in it (Laravel's `email` rule accepts it) was queued, retried four times, then lost — `mail.failed`
 * naming no member, the card shown as sent. One address rule, checked at every way in AND at the one sending path; a
 * permanent refusal fails once and names who; staff can see which members to fix.
 */
class SendableEmailTest extends TestCase
{
    use RefreshDatabase;

    private const BAD = ['juan @gmail.com', 'juan@gmail', 'josé@gmail.com', 'juan@gmaíl.com', '"juan perez"@gmail.com', 'juan@localhost', 'juan@gmail.c', 'juan@gmail,com'];

    private Organisation $org;

    private Location $location;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->owner = User::factory()->create(['email' => 'owner@club.test']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->location->id]);
        $this->actingAs($this->owner);
    }

    /** A member whose stored address is unsendable, written raw as legacy data would be. */
    private function legacyMember(string $email = 'juan @gmail.com'): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'email' => 'temp@club.test']);
        DB::table('members')->where('id', $member->id)->update(['email' => $email]);

        return $member->fresh();
    }

    private function assertSkippedFor(Member $member): void
    {
        $entries = AuditLog::query()->withoutGlobalScopes()->where('action', 'mail.skipped_invalid_address')->where('auditable_id', $member->id)->get();
        $this->assertNotEmpty($entries, 'the skip is audited with the member as subject');
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('gmail', (string) json_encode([$entry->before, $entry->after]), 'never the address');
        }
    }

    // --- 1. The rule -------------------------------------------------------------------------------------------------------------

    public function test_the_sendable_rule_refuses_what_laravels_email_rule_lets_through(): void
    {
        foreach (self::BAD as $bad) {
            $this->assertFalse(Email::isSendable($bad), $bad);
        }
        foreach (['juan@gmail.com', 'JUAN@Gmail.com ', 'juan.perez+club@hotmail.es', 'a@b.co.uk'] as $good) {
            $this->assertTrue(Email::isSendable($good), $good);
        }
        $this->assertFalse(Email::isSendable('juan@[127.0.0.1]'));
        $this->assertFalse(Email::isSendable(null));
    }

    // --- 2. The ways in ----------------------------------------------------------------------------------------------------------

    public function test_every_way_in_refuses_a_spaced_address_with_the_spanish_message(): void
    {
        $message = 'Este correo no es válido. Revisa que no tenga espacios ni acentos y que termine en algo como .com o .es.';

        // The public application and the counter alta share ApplicationShape.
        $shape = Validator::make(['email' => 'juan @gmail.com'], ['email' => ApplicationShape::facts()['email']]);
        $this->assertSame([$message], $shape->errors()->get('email'));
        $this->assertTrue(Validator::make(['email' => 'juan@gmail.com'], ['email' => ApplicationShape::facts()['email']])->passes());

        Livewire::test(CreateMember::class)->fillForm(['email' => 'juan @gmail.com'])->call('create')->assertHasFormErrors(['email']);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])->fillForm(['email' => 'juan @gmail.com'])->call('save')->assertHasFormErrors(['email']);
        Livewire::test(CreateUser::class)->fillForm(['email' => 'juan @gmail.com'])->call('create')->assertHasFormErrors(['email']);
        Livewire::test(ListMemberApplications::class)->callAction('invite', ['invite_mode' => 'email', 'applicant_email' => 'juan @gmail.com'])
            ->assertHasActionErrors(['applicant_email']);

        app()->setLocale('en');
        $this->assertSame([__('Este correo no es válido. Revisa que no tenga espacios ni acentos y que termine en algo como .com o .es.')],
            Validator::make(['email' => 'juan @gmail.com'], ['email' => [new SendableEmail]])->errors()->get('email'));
        $this->assertNotSame($message, __($message), 'translated in English');
    }

    // --- 3. The import -----------------------------------------------------------------------------------------------------------

    public function test_the_import_keeps_a_member_with_a_bad_email_but_without_the_email_and_warns(): void
    {
        $csv = "first_name,last_name,email,date_of_birth\nJuan,Pérez,\"juan@gmail,com\",1990-01-01\nAna,López,ana@gmail.com,1991-02-02\n";
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $csv);

        $result = (new ImportMembers)->import($path);
        @unlink($path);

        $this->assertSame(2, $result['created']);
        $this->assertNull(Member::query()->withoutGlobalScopes()->where('first_name', 'Juan')->value('email'));
        $this->assertSame('ana@gmail.com', Member::query()->withoutGlobalScopes()->where('first_name', 'Ana')->value('email'));
        $this->assertSame(['correo «juan@gmail,com» no válido, se importa sin correo'], $result['warnings'][2] ?? null);
    }

    // --- 4. Never queued to a bad address ----------------------------------------------------------------------------------------

    public function test_no_sender_queues_to_an_unsendable_address(): void
    {
        Mail::fake();
        $member = $this->legacyMember();

        $this->assertFalse((new SendMemberCard)->handle($member));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('action', 'member.card.sent')->count());

        $this->assertTrue((new IssueMemberLoginLink)->handle('juan @gmail.com'));

        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0, 'expires_at' => now()->addDays(3)]);
        $this->artisan('memberships:sweep')->assertSuccessful();
        $this->assertNotNull(Membership::query()->withoutGlobalScopes()->where('member_id', $member->id)->value('reminder_sent_for'), 'not re-tried every night');

        $convocatoria = Convocatoria::factory()->create(['organisation_id' => $this->org->id, 'type' => ConvocatoriaType::ORDINARY, 'held_at' => now()->addDays(30)]);
        DB::table('members')->where('id', $member->id)->update(['joined_at' => '2026-01-01']);
        (new IssueConvocatoria)->handle($convocatoria, $this->owner);
        $this->assertSame(ConvocatoriaRecipientStatus::NO_EMAIL, $convocatoria->fresh()->recipients()->where('member_id', $member->id)->sole()->status);

        $application = MemberApplication::factory()->submitted()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'status' => ApplicationStatus::PENDING, 'payload' => ['first_name' => 'Pepe', 'last_name' => 'Ruiz', 'email' => 'pepe@club.test',
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'), 'document_type' => 'DNI', 'document_number' => '87654321X', 'consents' => ['membership', 'data_processing']]]);
        $approved = (new ApproveApplication)->handle($application, $this->owner->id);
        DB::table('members')->where('id', $approved->id)->update(['email' => 'pepe @club.test']);
        Mail::fake(); // only what follows counts
        (new SendMemberCard)->handle($approved->fresh());

        Mail::assertNothingQueued();
        $this->assertSkippedFor($member);
    }

    public function test_the_counter_receipt_says_the_address_is_wrong_and_queues_nothing(): void
    {
        Mail::fake();
        $member = $this->legacyMember();
        $dispensation = Dispensation::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'member_id' => $member->id]);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($this->owner);

        Livewire::test(DispensaryPos::class)->set('lastDispensationId', $dispensation->id)->call('emailReceipt')
            ->assertSet('flashMessage', 'El correo del socio no es válido («juan @gmail.com»). Corrígelo en su ficha y vuelve a enviar.');
        Mail::assertNothingQueued();
    }

    // --- 5. A permanent refusal fails once, and names who ------------------------------------------------------------------------

    public function test_a_permanent_resend_refusal_fails_the_job_at_once_and_mail_failed_names_the_member(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'email' => 'juan@gmail.com']);
        Mail::fake();
        (new SendMemberCard)->handle($member);
        $mail = null;
        Mail::assertQueued(MemberCardMail::class, function (MemberCardMail $queued) use (&$mail): bool {
            $mail = $queued;

            return true;
        });
        $this->assertSame([$member->getMorphClass(), $member->id], [$mail->aboutType, $mail->aboutId]);

        $refusal = fn (int $code): TransportException => new TransportException('Request to Resend API failed. Reason: Invalid `to` field.', 0,
            new ErrorException(['message' => 'Invalid `to` field.', 'name' => 'validation_error', 'statusCode' => $code]));
        $job = new class
        {
            public ?\Throwable $failedWith = null;

            public function fail($e = null): void
            {
                $this->failedWith = $e;
            }
        };
        [$middleware] = $mail->middleware();

        foreach ([422 => true, 429 => false, 500 => false] as $code => $permanent) {
            $job->failedWith = null;
            try {
                $middleware->handle($job, fn () => throw $refusal($code));
            } catch (TransportException) {
            }
            $this->assertSame($permanent, $job->failedWith !== null, "code {$code}");
        }

        $mail->failed($refusal(422));
        $entry = AuditLog::query()->withoutGlobalScopes()->where('action', 'mail.failed')->latest('id')->first();
        $this->assertSame([$member->getMorphClass(), $member->id], [$entry->auditable_type, (string) $entry->getAttribute('auditable_id')]);
        $this->assertTrue($entry->after['permanent']);
        $this->assertSame('validation_error', $entry->after['error_type']);
        $this->assertStringNotContainsString('gmail', (string) json_encode($entry->after));
    }

    // --- 6. Users get the bare address --------------------------------------------------------------------------------------------

    public function test_staff_mail_goes_to_the_bare_address_without_a_display_name(): void
    {
        Mail::fake();
        $this->owner->forceFill(['alert_preferences' => ['channels' => ['email']], 'name' => 'Olga Dueña'])->save();
        OwnerAlertState::query()->withoutGlobalScopes()->create(['organisation_id' => $this->org->id, 'type' => AlertType::SYSTEM,
            'subject' => 'component:scheduler', 'location_id' => null, 'detail' => ['component' => 'scheduler'], 'active_since' => now()]);
        $this->travelTo(now()->setTime(9, 0));

        (new SendMorningSummaries)->handle($this->org);

        Mail::assertQueued(AlertSummaryMail::class, fn ($mail): bool => $mail->to === [['name' => null, 'address' => 'owner@club.test']]);
    }

    // --- 7. One sending path -------------------------------------------------------------------------------------------------------

    public function test_nothing_in_app_calls_mail_to_except_the_one_sending_path(): void
    {
        $this->assertSame([], self::directSends(File::allFiles(app_path())));
        $planted = new class('planted.php', '', 'planted.php') extends \Symfony\Component\Finder\SplFileInfo
        {
            public function getContents(): string
            {
                return "<?php class X { function f() { \\Illuminate\\Support\\Facades\\Mail::to('a@b.es')->queue(new Y); } }";
            }
        };
        $this->assertSame(['planted.php'], self::directSends([$planted]));
    }

    /** @param iterable<\SplFileInfo> $files  @return list<string> */
    private static function directSends(iterable $files): array
    {
        $found = [];
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, 'Actions/Mail/QueueClubMail.php') || str_contains($path, 'DevMail')) {
                continue;
            }
            $source = method_exists($file, 'getContents') ? $file->getContents() : (string) file_get_contents($path);
            if (preg_match('/Mail::(to|send|queue)\(/', $source) === 1) {
                $found[] = $path;
            }
        }

        return $found;
    }

    // --- 8. Staff can see which members to fix ------------------------------------------------------------------------------------

    public function test_staff_see_which_members_to_fix(): void
    {
        $bad = $this->legacyMember();
        $good = Member::factory()->create(['organisation_id' => $this->org->id, 'email' => 'good@club.test']);

        $this->assertFalse($bad->emailIsSendable());
        $this->assertTrue($bad->cardMissing());

        $this->get(MemberResource::getUrl('edit', ['record' => $bad]))
            ->assertSee('Este correo no es válido: no le llegan correos (carné, recordatorios, convocatorias).');

        Livewire::test(ListMembers::class)->filterTable('email_invalid', true)
            ->assertCanSeeTableRecords([$bad])->assertCanNotSeeTableRecords([$good]);

        $this->assertSame(1, (new SystemHealth)->invalidEmails()['members']);
        $this->artisan('mail:invalid-addresses')->expectsOutputToContain('juan @gmail.com')->assertSuccessful();
    }
}
