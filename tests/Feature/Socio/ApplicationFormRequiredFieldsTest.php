<?php

namespace Tests\Feature\Socio;

use App\Enums\ApplicationStatus;
use App\Http\Requests\SubmitApplicationRequest;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\ApplicationSpamGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 155 part A — the public application form must identify which fields are required, visually AND
 * programmatically (WCAG 3.3.2), and a failed submit must land the applicant on the first problem field.
 * The marking is asserted against SubmitApplicationRequest's OWN rules, so the form and the rules cannot drift.
 */
class ApplicationFormRequiredFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function invite(string $token): void
    {
        MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'invite_token_hash' => hash('sha256', $token),
            'status' => ApplicationStatus::PENDING,
            'payload' => [],
        ]);
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [required, optional] field names, from the request rules.
     */
    private function requiredAndOptionalFields(): array
    {
        $required = [];
        $optional = [];
        foreach ((new SubmitApplicationRequest)->rules() as $field => $rules) {
            $rules = is_array($rules) ? $rules : explode('|', (string) $rules);
            $isRequired = false;
            foreach ($rules as $rule) {
                if (is_string($rule) && in_array($rule, ['required', 'accepted'], true)) {
                    $isRequired = true;
                }
            }
            $isRequired ? $required[] = $field : $optional[] = $field;
        }

        return [$required, $optional];
    }

    public function test_every_required_field_is_marked_required_and_no_optional_field_is_matching_the_rules(): void
    {
        [$required, $optional] = $this->requiredAndOptionalFields();
        $this->invite('t');
        $html = (string) $this->get(route('socio.application', ['token' => 't']))->assertOk()->getContent();

        // Programmatic marking (what assistive tech announces) is on EVERY required field...
        foreach ($required as $field) {
            $this->assertMatchesRegularExpression(
                '/name="'.preg_quote($field, '/').'"[^>]*\srequired[\s>]/',
                $html,
                "Required field '{$field}' must carry the `required` attribute (assistive-tech signal)."
            );
        }
        // ...and on NO optional field.
        foreach ($optional as $field) {
            $this->assertDoesNotMatchRegularExpression(
                '/name="'.preg_quote($field, '/').'"[^>]*\srequired[\s>]/',
                $html,
                "Optional field '{$field}' must NOT be marked required."
            );
        }

        // The visual convention is stated in instructions (the legend), not left to the error message.
        $this->assertStringContainsString(__('Campos obligatorios'), $html);
    }

    public function test_a_failed_submission_lands_the_applicant_on_the_first_problem_field(): void
    {
        $this->invite('t');

        // Submit with the FIRST required field (first_name) empty → validation fails and the form re-renders.
        $content = (string) $this->from(route('socio.application', ['token' => 't']))
            ->followingRedirects()
            ->post(route('socio.application.store', ['token' => 't']), [
                'first_name' => '', 'last_name' => 'García', 'email' => 'maria@example.es',
                'date_of_birth' => now()->subYears(30)->format('Y-m-d'),
                'document_type' => 'DNI', 'document_number' => '12345678Z',
                'consent_data' => '1', 'consent_statutes' => '1',
                ApplicationSpamGuard::HONEYPOT => '',
                ApplicationSpamGuard::TIMESTAMP => ApplicationSpamGuard::issueToken(),
            ])->getContent();

        // The re-rendered form carries a script focusing the first errored field — first_name.
        $this->assertMatchesRegularExpression('/querySelector\([^)]*first_name[^)]*\)\?\.focus/', $content);
    }
}
