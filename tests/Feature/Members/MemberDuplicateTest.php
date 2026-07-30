<?php

namespace Tests\Feature\Members;

use App\Actions\Members\FindDuplicateMembers;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    public function test_matching_name_and_dob_surfaces_the_existing_member(): void
    {
        $existing = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'first_name' => 'María', 'last_name' => 'García',
            'date_of_birth' => '1990-05-01',
        ]);

        $matches = (new FindDuplicateMembers)->handle([
            'first_name' => 'María', 'last_name' => 'García', 'date_of_birth' => '1990-05-01',
        ]);

        $this->assertTrue($matches->contains('id', $existing->id));
    }

    public function test_matching_document_number_surfaces_the_existing_member(): void
    {
        $existing = Member::factory()->create([
            'organisation_id' => $this->org->id, 'document_number' => '12345678Z',
        ]);

        $matches = (new FindDuplicateMembers)->handle(['document_number' => '12345678-z']); // normalised

        $this->assertTrue($matches->contains('id', $existing->id));
    }

    public function test_no_false_positive_for_an_unrelated_person(): void
    {
        Member::factory()->create(['organisation_id' => $this->org->id, 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'date_of_birth' => '1985-01-01']);

        $matches = (new FindDuplicateMembers)->handle([
            'first_name' => 'Juan', 'last_name' => 'Pérez', 'date_of_birth' => '1970-03-03',
        ]);

        $this->assertTrue($matches->isEmpty());
    }
}
