<?php

namespace Tests\Feature\Members;

use App\Models\Member;
use App\Models\Organisation;
use App\Support\MemberNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 77 — member numbers come from a durable, monotonic per-org sequence (was COUNT(*) + 1). This is
 * the DETERMINISTIC half of the fix, testable without concurrency: the number never reissues after a member
 * is deleted/purged, and it continues above whatever was already issued.
 */
class MemberNumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_are_distinct_and_monotonic(): void
    {
        $org = Organisation::factory()->create();

        $a = MemberNumber::next($org->id);
        $b = MemberNumber::next($org->id);
        $c = MemberNumber::next($org->id);

        $this->assertSame([$a, $b, $c], array_unique([$a, $b, $c])); // all distinct
        $this->assertTrue($a < $b && $b < $c);                       // strictly increasing
    }

    public function test_a_number_is_not_reissued_after_a_member_is_purged(): void
    {
        $org = Organisation::factory()->create();

        $first = MemberNumber::next($org->id);
        $member = Member::factory()->create(['organisation_id' => $org->id, 'member_no' => $first]);
        $member->forceDelete(); // a retention purge removes the row entirely

        // COUNT(*) + 1 would now reissue $first (count back to 0); the sequence must NOT.
        $this->assertNotSame($first, MemberNumber::next($org->id));
    }

    public function test_the_sequence_continues_above_the_high_water_mark(): void
    {
        $org = Organisation::factory()->create(['member_no_sequence' => 41]);

        // 41 already issued (migration backfills this from the max existing number) → next is 42.
        $this->assertSame('M-00042', MemberNumber::next($org->id));
        $this->assertSame(42, $org->refresh()->member_no_sequence);
    }
}
