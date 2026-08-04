<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ApproveApplication;
use App\Enums\ApplicationStatus;
use App\Enums\MemberStatus;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MemberOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function application(string $dob): MemberApplication
    {
        return MemberApplication::factory()->submitted()->create([
            'organisation_id' => $this->org->id,
            'status' => ApplicationStatus::PENDING,
            'payload' => [
                'first_name' => 'María', 'last_name' => 'García', 'date_of_birth' => $dob,
                'document_type' => 'DNI', 'document_number' => '12345678Z',
                'consents' => ['membership', 'data_processing'],
            ],
        ]);
    }

    public function test_approving_an_adult_application_creates_an_active_member(): void
    {
        $application = $this->application(now()->subYears(30)->toDateString());

        $member = (new ApproveApplication)->handle($application);

        $this->assertSame(MemberStatus::ACTIVE, $member->status);
        $this->assertNotNull($member->member_no);
        $this->assertNotNull($member->carencia_ends_at);
        $this->assertSame(ApplicationStatus::APPROVED, $application->fresh()->status);
        $this->assertSame(2, $member->consents()->count()); // versioned consent captured
    }

    public function test_an_under_age_application_cannot_be_approved(): void
    {
        // A genuine submission carrying a real, underage date of birth still gets the AGE message unchanged
        // (prompt 152) — the submission gate is passed, so the reason surfaced is the correct one.
        $application = $this->application(now()->subYears(16)->toDateString());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('El solicitante es menor de la edad mínima configurada.'));
        (new ApproveApplication)->handle($application);
    }
}
