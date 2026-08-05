<?php

namespace Tests\Feature\Socio;

use App\Enums\ApplicationStatus;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security-audit finding on prompt 157 — a rejected/abandoned member application kept its Article-9-adjacent
 * payload (name, DOB, email, document number) and its optional ID photo forever; the prompt-157 comment claimed
 * a sweep handled it, but none did. `applications:prune-retention` now anonymises them past
 * application_retention_days and deletes the photo — but NEVER an approved application, whose photo the member
 * now shares.
 */
class ApplicationRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    /** @return array{MemberApplication, string} */
    private function application(ApplicationStatus $status, int $ageDays): array
    {
        $path = 'member-photos/'.Str::ulid().'.jpg';
        Storage::disk('documents')->put($path, 'ciphertext');

        $application = MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => $status,
            'applicant_email' => 'prospect@example.test',
            'payload' => ['first_name' => 'Ana', 'document_number' => '12345678Z', 'photo_path' => $path],
        ]);

        // Age the row past/within the retention window (query builder sets updated_at exactly).
        MemberApplication::withoutGlobalScopes()->whereKey($application->id)
            ->update(['updated_at' => now()->subDays($ageDays)]);

        return [$application->fresh(), $path];
    }

    public function test_a_rejected_application_past_retention_is_anonymised_and_its_photo_deleted(): void
    {
        [$application, $path] = $this->application(ApplicationStatus::REJECTED, 200); // > 180-day default

        $this->artisan('applications:prune-retention')->assertSuccessful();

        $this->assertFalse(Storage::disk('documents')->exists($path));   // Article-9 photo gone
        $fresh = $application->fresh();
        $this->assertNull($fresh->payload);                              // personal data scrubbed
        $this->assertNull($fresh->applicant_email);
        $this->assertSame(ApplicationStatus::REJECTED, $fresh->status);  // the row shell (outcome) survives
    }

    public function test_an_approved_application_is_never_touched_so_the_members_photo_survives(): void
    {
        [$application, $path] = $this->application(ApplicationStatus::APPROVED, 200);

        $this->artisan('applications:prune-retention')->assertSuccessful();

        // The approved member SHARES this photo file — pruning it would blank their counter photo.
        $this->assertTrue(Storage::disk('documents')->exists($path));
        $this->assertNotNull($application->fresh()->payload);
    }

    public function test_a_recent_application_within_the_window_is_untouched(): void
    {
        [$application, $path] = $this->application(ApplicationStatus::REJECTED, 10); // within 180 days

        $this->artisan('applications:prune-retention')->assertSuccessful();

        $this->assertTrue(Storage::disk('documents')->exists($path));
        $this->assertNotNull($application->fresh()->payload);
    }

    public function test_it_is_idempotent(): void
    {
        [$application, $path] = $this->application(ApplicationStatus::REJECTED, 200);

        $this->artisan('applications:prune-retention')->assertSuccessful();
        $this->artisan('applications:prune-retention')->assertSuccessful(); // second run is a no-op

        $this->assertFalse(Storage::disk('documents')->exists($path));
        $this->assertNull($application->fresh()->payload);
    }

    public function test_dry_run_writes_nothing(): void
    {
        [$application, $path] = $this->application(ApplicationStatus::REJECTED, 200);

        $this->artisan('applications:prune-retention', ['--dry-run' => true])->assertSuccessful();

        $this->assertTrue(Storage::disk('documents')->exists($path));
        $this->assertNotNull($application->fresh()->payload);
    }
}
