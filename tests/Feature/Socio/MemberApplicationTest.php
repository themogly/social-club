<?php

namespace Tests\Feature\Socio;

use App\Enums\ApplicationStatus;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberApplicationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function invite(string $rawToken): MemberApplication
    {
        return MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', $rawToken),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);
    }

    public function test_the_invite_route_renders_for_a_valid_token(): void
    {
        $this->invite('valid-raw-token');

        $response = $this->get(route('socio.application', ['token' => 'valid-raw-token']));
        $response->assertOk();
        $response->assertSee(__('Solicitud de alta'));
        $response->assertSee(__('Enviar solicitud'));
    }

    public function test_the_invite_route_404s_for_an_invalid_token(): void
    {
        $this->invite('the-real-token');

        $this->get(route('socio.application', ['token' => 'not-the-real-token']))->assertNotFound();
    }

    public function test_a_decided_application_link_is_dead(): void
    {
        $application = $this->invite('decided-token');
        $application->update(['status' => ApplicationStatus::APPROVED]);

        // Once approved/rejected the invite no longer opens the form.
        $this->get(route('socio.application', ['token' => 'decided-token']))->assertNotFound();
    }

    public function test_submitting_the_application_stores_the_payload_and_stays_pending(): void
    {
        $application = $this->invite('submit-token');

        $this->post(route('socio.application.store', ['token' => 'submit-token']), [
            'first_name' => 'María',
            'last_name' => 'García',
            'email' => 'maria@example.es',
            'phone' => '600123123',
            'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
            'document_type' => 'DNI',
            'document_number' => '12345678Z',
            'declared_monthly_g' => '30',
            'consent' => '1',
        ])->assertRedirect(route('socio.application', ['token' => 'submit-token']));

        $application->refresh();
        $this->assertSame(ApplicationStatus::PENDING, $application->status);
        $this->assertSame('María', $application->payload['first_name']);
        $this->assertSame('maria@example.es', $application->payload['email']);
        // Weight is stored as integer centigrams at the edge: 30 g → 3000 cg.
        $this->assertSame(3000, $application->payload['declared_monthly_cg']);
    }

    public function test_an_underage_application_is_rejected_and_not_stored(): void
    {
        $application = $this->invite('under-token');

        $this->post(route('socio.application.store', ['token' => 'under-token']), [
            'first_name' => 'Joven',
            'last_name' => 'Persona',
            'email' => 'joven@example.es',
            'date_of_birth' => now()->subYears(16)->format('Y-m-d'),
            'document_type' => 'DNI',
            'document_number' => '00000000A',
            'consent' => '1',
        ])->assertSessionHasErrors('date_of_birth');

        $application->refresh();
        $this->assertSame([], $application->payload);
    }
}
