<?php

namespace Tests\Feature\Members;

use App\Actions\Members\RejectApplication;
use App\Enums\ApplicationStatus;
use App\Enums\Role;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Mail\ApplicationRejectedMail;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Post-296 completeness P4 — rejecting an application was a status write and a mail inside a Filament closure, with no
 * audit entry, while approving it is an Action. It is one now: the decision, who took it, the mail, and the trail.
 */
class RejectApplicationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
    }

    private function submitted(): MemberApplication
    {
        return MemberApplication::factory()->submitted()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'status' => ApplicationStatus::PENDING,
            'payload' => ['first_name' => 'John', 'last_name' => 'Doe', 'email' => 'john@example.test', 'consent_locale' => 'en'],
        ]);
    }

    public function test_rejecting_from_the_panel_is_recorded_and_the_applicant_told(): void
    {
        Mail::fake();
        $application = $this->submitted();

        Livewire::actingAs($this->owner)->test(ListMemberApplications::class)
            ->callTableAction('reject', $application, ['reason' => 'Aval no válido']);

        $application->refresh();
        $this->assertSame(ApplicationStatus::REJECTED, $application->status);
        $this->assertSame($this->owner->id, $application->reviewed_by);
        Mail::assertQueued(ApplicationRejectedMail::class, fn (ApplicationRejectedMail $mail): bool => $mail->hasTo('john@example.test'));
        $audit = AuditLog::query()->where('action', 'application.rejected')->sole();
        $this->assertStringNotContainsString('john@example.test', (string) json_encode($audit->toArray()), 'the trail holds no address');
    }

    public function test_a_decided_application_cannot_be_rejected_again(): void
    {
        $application = $this->submitted();
        $application->forceFill(['status' => ApplicationStatus::APPROVED])->save();

        $this->expectException(DomainException::class);
        (new RejectApplication)->handle($application, $this->owner, 'x');
    }
}
