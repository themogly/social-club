<?php

namespace Tests\Feature\Members;

use App\Actions\Members\IssueMemberToken;
use App\Actions\Members\ResolveMemberByToken;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberTokenTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->member = Member::factory()->create(['organisation_id' => $org->id]);
    }

    public function test_a_token_resolves_to_its_member_and_is_not_derived_from_the_id(): void
    {
        $token = (new IssueMemberToken)->handle($this->member);

        $this->assertNotSame($this->member->id, $token);
        $this->assertFalse(str_contains($token, $this->member->id));
        $this->assertTrue((new ResolveMemberByToken)->handle($token)?->is($this->member));
    }

    public function test_regenerating_invalidates_the_old_token(): void
    {
        $old = (new IssueMemberToken)->handle($this->member);
        $new = (new IssueMemberToken)->handle($this->member);

        $this->assertNull((new ResolveMemberByToken)->handle($old));         // revoked
        $this->assertTrue((new ResolveMemberByToken)->handle($new)?->is($this->member));
    }

    public function test_an_unknown_or_integer_like_token_resolves_to_nothing(): void
    {
        $this->assertNull((new ResolveMemberByToken)->handle('123'));
        $this->assertNull((new ResolveMemberByToken)->handle('not-a-real-token'));
    }
}
