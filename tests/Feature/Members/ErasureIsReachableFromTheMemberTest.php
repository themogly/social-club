<?php

namespace Tests\Feature\Members;

use App\Enums\DataRequestType;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Models\DataRequest;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin audit, Phase C — "Eliminar" is not erasure, and erasure has to be reachable from the person.
 *
 * `Member` uses SoftDeletes, so Delete hides the record and keeps every identifying field: name, DNI, email,
 * phone, photo and ID scan all remain in the database and on the encrypted disk. Real erasure is
 * `AnonymiseMember`, and it was reachable ONLY by creating a Data Request from another screen — nothing on
 * the member record pointed at it. An owner told *"erase this person"* would press the button labelled
 * Delete and reasonably believe they had complied. That is an Article 17 misreading with legal consequences.
 *
 * The fix is a signpost, not a second writer: the member record now registers the ERASE request and sends
 * the operator to the screen that fulfils it, behind `data.erase`, with the DataRequest row standing as the
 * evidence that the club received a request and answered it.
 */
class ErasureIsReachableFromTheMemberTest extends TestCase
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

    private function actor(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);

        return $user;
    }

    private function member(): Member
    {
        return Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'first_name' => 'Yvette',
            'last_name' => 'Rosales',
        ]);
    }

    public function test_the_member_record_offers_the_erasure_route(): void
    {
        $this->actor(Role::OWNER); // holds data.request.handle
        $member = $this->member();

        Livewire::test(EditMember::class, ['record' => $member->id])
            ->assertOk()
            ->assertActionExists('requestErasure')
            ->callAction('requestErasure');

        $request = DataRequest::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame($member->id, $request->member_id);
        $this->assertSame(DataRequestType::ERASE, $request->type);
        $this->assertNotNull($request->requested_at);
        $this->assertNull($request->completed_at, 'registering the request must not fulfil it');
    }

    /** The signpost does NOT anonymise: fulfilment stays behind data.erase on the screen that owns it. */
    public function test_registering_the_request_does_not_anonymise_anybody(): void
    {
        $this->actor(Role::OWNER);
        $member = $this->member();

        Livewire::test(EditMember::class, ['record' => $member->id])->callAction('requestErasure');

        $member->refresh();
        $this->assertSame('Yvette', $member->first_name);
        $this->assertNull($member->deleted_at);
    }

    /**
     * The denial test: a role that CAN edit the member but may not handle RGPD requests is not shown a route
     * it cannot walk. MANAGER, deliberately — STAFF cannot open the edit page at all (403 on `members.edit`),
     * so it would prove nothing about the action's own gate.
     */
    public function test_a_role_without_the_rgpd_permission_never_sees_the_action(): void
    {
        $this->actor(Role::MANAGER);
        $member = $this->member();

        Livewire::test(EditMember::class, ['record' => $member->id])
            ->assertOk()
            ->assertActionHidden('requestErasure');

        $this->assertSame(0, DataRequest::query()->withoutGlobalScopes()->count());
    }

    /**
     * The claim behind the finding, pinned: Delete is NOT erasure.
     *
     * If this ever starts failing because a delete began scrubbing fields, the signpost above is redundant
     * and the audit note should be revisited — but silently discovering that is exactly what this prevents.
     */
    public function test_a_soft_delete_keeps_every_identifying_field(): void
    {
        $this->actor(Role::MANAGER);
        $member = $this->member();

        $member->delete();

        $deleted = Member::query()->withoutGlobalScopes()->withTrashed()->findOrFail($member->id);

        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame('Yvette', $deleted->first_name);
        $this->assertSame('Rosales', $deleted->last_name);
    }
}
