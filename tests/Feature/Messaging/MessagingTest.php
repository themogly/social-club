<?php

namespace Tests\Feature\Messaging;

use App\Actions\Members\AnonymiseMember;
use App\Actions\Messaging\CloseThread;
use App\Actions\Messaging\ConvertThreadToDataRequest;
use App\Actions\Messaging\ReplyToThread;
use App\Actions\Messaging\SendMemberMessage;
use App\Enums\DataRequestType;
use App\Enums\MessageAuthor;
use App\Enums\MessageThreadStatus;
use App\Enums\Role;
use App\Filament\Resources\MessageThreads\MessageThreadResource;
use App\Models\DataRequest;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Organisation;
use App\Models\User;
use App\Notifications\NewMemberMessageNotification;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 136 — the club side: staff reply / close / convert-to-RGPD (all comms.manage), the member-reopens
 * rule, erasure of member-authored content, and the retention sweep. Every write goes through its Action.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->manager = User::factory()->create();
        $this->manager->assignRole(Role::MANAGER->value); // MANAGER holds comms.manage
        // A configured VAPID keypair so the reply's push notification routes (via() is otherwise empty).
        config(['webpush.vapid.public_key' => 'BPUBLICKEY', 'webpush.vapid.private_key' => 'PRIVATEKEY']);
        Notification::fake();
    }

    private function member(): Member
    {
        return Member::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function thread(Member $member, string $subject = 'Asunto', string $body = 'mi texto'): MessageThread
    {
        $thread = MessageThread::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'subject' => $subject]);
        Message::factory()->create(['thread_id' => $thread->id, 'author' => MessageAuthor::MEMBER, 'body' => $body]);

        return $thread;
    }

    public function test_a_manager_reply_appends_marks_the_member_message_read_and_notifies(): void
    {
        $member = $this->member();
        $thread = $this->thread($member);

        (new ReplyToThread)->handle($thread, $this->manager, 'Te respondemos');

        $this->assertSame(2, $thread->messages()->count());
        $this->assertSame(MessageAuthor::STAFF, $thread->messages()->latest('created_at')->first()?->author);
        $this->assertNotNull($thread->messages()->where('author', MessageAuthor::MEMBER->value)->first()?->read_at);
        Notification::assertSentTo($member, NewMemberMessageNotification::class);
    }

    public function test_a_staff_user_cannot_reply(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $this->assertFalse($staff->can('comms.manage'));
        $thread = $this->thread($this->member());

        $this->expectException(AuthorizationException::class);
        (new ReplyToThread)->handle($thread, $staff, 'no debería');
    }

    public function test_a_reply_can_close_the_thread(): void
    {
        $thread = $this->thread($this->member());

        (new ReplyToThread)->handle($thread, $this->manager, 'Cerramos', close: true);

        $this->assertSame(MessageThreadStatus::CLOSED, $thread->refresh()->status);
        $this->assertNotNull($thread->closed_at);
    }

    public function test_a_member_writing_again_reopens_a_closed_thread(): void
    {
        $member = $this->member();
        $thread = $this->thread($member);
        (new CloseThread)->handle($thread, $this->manager);
        $this->assertSame(MessageThreadStatus::CLOSED, $thread->refresh()->status);

        (new SendMemberMessage)->append($member, $thread, 'Sigo teniendo dudas');

        $this->assertSame(MessageThreadStatus::OPEN, $thread->refresh()->status);
        $this->assertNull($thread->closed_at);
    }

    public function test_converting_a_thread_creates_a_linked_data_request_and_closes_it(): void
    {
        $member = $this->member();
        $thread = $this->thread($member, 'Quiero que borréis mis datos');

        $request = (new ConvertThreadToDataRequest)->handle($thread, $this->manager, DataRequestType::ERASE, 'Pedido por mensaje');

        $this->assertInstanceOf(DataRequest::class, $request);
        $this->assertSame($member->id, $request->member_id);
        $this->assertSame(DataRequestType::ERASE, $request->type);
        $this->assertNull($request->completed_at);                       // logged, not yet fulfilled
        $this->assertSame($request->id, $thread->refresh()->data_request_id);
        $this->assertSame(MessageThreadStatus::CLOSED, $thread->status);
    }

    public function test_converting_a_thread_twice_is_refused(): void
    {
        $thread = $this->thread($this->member());
        (new ConvertThreadToDataRequest)->handle($thread, $this->manager, DataRequestType::ACCESS);

        $this->expectException(RuntimeException::class);
        (new ConvertThreadToDataRequest)->handle($thread->refresh(), $this->manager, DataRequestType::ACCESS);
    }

    public function test_anonymising_redacts_the_subject_and_member_bodies_but_keeps_staff_replies_and_the_thread(): void
    {
        $member = $this->member();
        $thread = $this->thread($member, 'Mi asunto personal', 'mi texto privado');
        (new ReplyToThread)->handle($thread, $this->manager, 'respuesta del club');

        (new AnonymiseMember)->handle($member);

        $thread->refresh();
        $this->assertSame('[borrado]', $thread->subject);
        $this->assertNotNull(MessageThread::withoutGlobalScopes()->find($thread->id)); // thread kept as evidence
        $this->assertSame('[borrado]', $thread->messages()->where('author', MessageAuthor::MEMBER->value)->first()?->body);
        $this->assertSame('respuesta del club', $thread->messages()->where('author', MessageAuthor::STAFF->value)->first()?->body);
    }

    public function test_the_retention_sweep_redacts_old_bodies_keeps_recent_and_is_idempotent(): void
    {
        $member = $this->member();
        $thread = $this->thread($member, 'Asunto', 'texto antiguo');
        $old = $thread->messages()->first();
        Message::query()->where('id', $old?->id)->update(['created_at' => now()->subDays(900)]);
        $recent = Message::factory()->create(['thread_id' => $thread->id, 'author' => MessageAuthor::MEMBER, 'body' => 'texto reciente']);

        $this->artisan('messages:prune-retention')->assertSuccessful();

        $this->assertSame('[borrado]', $old?->refresh()->body);           // past retention → redacted
        $this->assertSame('texto reciente', $recent->refresh()->body);    // inside retention → kept

        // Idempotent — a second run redacts nothing more and does not throw.
        $this->artisan('messages:prune-retention')->assertSuccessful();
        $this->assertSame('[borrado]', $old->refresh()->body);
    }

    public function test_the_admin_index_is_forbidden_without_comms_manage(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);

        $this->actingAs($staff)->get(MessageThreadResource::getUrl('index'))->assertForbidden();
        $this->actingAs($this->manager)->get(MessageThreadResource::getUrl('index'))->assertOk();
    }
}
