<?php

namespace Tests\Feature\Members;

use App\Actions\Members\AnonymiseMember;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberErasureTest extends TestCase
{
    use RefreshDatabase;

    public function test_erasure_anonymises_the_member_but_leaves_the_ledger_intact(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);

        $member = Member::factory()->create([
            'organisation_id' => $org->id, 'first_name' => 'María', 'email' => 'maria@example.com',
        ]);

        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $org->id, 'location_id' => $location->id, 'member_id' => $member->id,
            'total_cents' => 5000, 'cash_cents' => 5000, 'wallet_cents' => 0,
        ]);

        (new AnonymiseMember)->handle($member);
        $member->refresh();

        // Personal data scrubbed...
        $this->assertSame('ANONIMIZADO', $member->first_name);
        $this->assertNull($member->email);
        $this->assertNull($member->document_number);
        $this->assertNotNull($member->anonymised_at);

        // ...but the ledger is intact and unchanged.
        $this->assertDatabaseHas('dispensations', ['id' => $dispensation->id, 'member_id' => $member->id]);
        $this->assertSame(5000, $dispensation->fresh()->total_cents->cents);
    }
}
