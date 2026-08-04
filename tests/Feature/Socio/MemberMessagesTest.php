<?php

namespace Tests\Feature\Socio;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\MessageAuthor;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\MessageThread;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 136 — the socio side of messaging. Everything is scoped to the authenticated member on the `member`
 * guard: no member id in any URL, and a thread is reachable ONLY by its owner (findOrFail → 404 otherwise).
 */
class MemberMessagesTest extends TestCase
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

    private function member(?Organisation $org = null): Member
    {
        $org ??= $this->org;
        $member = Member::factory()->create(['organisation_id' => $org->id, 'status' => MemberStatus::ACTIVE]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $org->id]);
        Membership::factory()->create([
            'organisation_id' => $org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    public function test_a_member_starts_a_thread_and_it_is_theirs(): void
    {
        $member = $this->member();

        $this->actingAs($member, 'member')
            ->post(route('socio.messages.store'), ['subject' => 'Una pregunta', 'body' => 'Hola club'])
            ->assertRedirect();

        $thread = MessageThread::withoutGlobalScopes()->where('member_id', $member->id)->first();
        $this->assertNotNull($thread);
        $this->assertSame('Una pregunta', $thread->subject);
        $this->assertSame(1, $thread->messages()->count());
        $this->assertSame(MessageAuthor::MEMBER, $thread->messages()->first()?->author);
    }

    public function test_a_member_appends_to_their_own_thread(): void
    {
        $member = $this->member();
        $thread = MessageThread::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id]);

        $this->actingAs($member, 'member')
            ->post(route('socio.messages.reply', ['thread' => $thread->id]), ['body' => 'Otra cosa'])
            ->assertRedirect();

        $this->assertSame(1, $thread->messages()->count());
    }

    public function test_a_member_cannot_open_another_members_thread(): void
    {
        $mine = $this->member();
        $other = $this->member();
        $otherThread = MessageThread::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $other->id]);

        $this->actingAs($mine, 'member')
            ->get(route('socio.messages.show', ['thread' => $otherThread->id]))
            ->assertNotFound();

        // Nor write to it.
        $this->actingAs($mine, 'member')
            ->post(route('socio.messages.reply', ['thread' => $otherThread->id]), ['body' => 'intruso'])
            ->assertNotFound();
        $this->assertSame(0, $otherThread->messages()->count());
    }

    public function test_a_member_cannot_reach_a_thread_in_another_org(): void
    {
        $mine = $this->member();
        $otherOrg = Organisation::factory()->create();
        $foreign = $this->member($otherOrg);
        $foreignThread = MessageThread::factory()->create(['organisation_id' => $otherOrg->id, 'member_id' => $foreign->id]);

        $this->actingAs($mine, 'member')
            ->get(route('socio.messages.show', ['thread' => $foreignThread->id]))
            ->assertNotFound();
    }

    public function test_the_messages_route_redirects_when_unauthenticated(): void
    {
        $this->get(route('socio.messages'))->assertRedirect(route('socio.login'));
    }

    public function test_the_index_lists_only_the_members_own_threads(): void
    {
        $member = $this->member();
        $other = $this->member();
        MessageThread::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'subject' => 'ASUNTO-PROPIO']);
        MessageThread::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $other->id, 'subject' => 'ASUNTO-AJENO']);

        $this->actingAs($member, 'member')
            ->get(route('socio.messages'))
            ->assertOk()
            ->assertSee('ASUNTO-PROPIO')
            ->assertDontSee('ASUNTO-AJENO');
    }
}
