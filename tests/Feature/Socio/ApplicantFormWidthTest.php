<?php

namespace Tests\Feature\Socio;

use App\Actions\Members\IssueApplicationInvite;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 227 — the applicant's form was phone-width on every screen.
 *
 * The owner, opening an emailed invitation in desktop Chrome: *"the form is very squashed on the screen — can
 * we make it take up more width."* It was `max-w-sm` (384px) on the page INSIDE the socio layout's
 * `max-w-md` (448px) — two phone caps nested, so a 2560px monitor showed a 384px column.
 *
 * The fix is an OPT-IN on the layout, and this file's job is to prove it stayed opt-in: the member app is
 * phone-first by design and every other page there must be untouched.
 */
class ApplicantFormWidthTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->location = Location::factory()->create(['organisation_id' => $org->id]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole(Role::MANAGER->value);
        $this->manager->locations()->sync([$this->location->id]);
    }

    private function applicationHtml(): string
    {
        $application = (new IssueApplicationInvite)->handle($this->manager, $this->location->id, null, 'width');

        return (string) $this->get(route('socio.application', ['token' => $application->invite_token]))
            ->assertOk()->getContent();
    }

    /** The form opts in, and its own second cap is gone. */
    public function test_the_application_form_opts_into_the_wide_shell(): void
    {
        $html = $this->applicationHtml();

        $this->assertStringContainsString('max-w-3xl', $html, 'the form is still capped at phone width');
        $this->assertStringNotContainsString('max-w-sm', $html, 'the page still carries its own phone cap');
        $this->assertStringNotContainsString('max-w-md', $html, 'the layout still caps this page at phone width');
    }

    /**
     * **The opt-in is this page's alone.**
     *
     * Asserted on the member MENU — the page a socio uses daily, and the one where a regression here would
     * be felt first.
     */
    public function test_every_other_socio_page_keeps_the_phone_cap(): void
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->location->organisation_id,
            'status' => MemberStatus::ACTIVE,
        ]);

        $html = (string) $this->actingAs($member, 'member')->get(route('socio.menu'))->assertOk()->getContent();

        $this->assertStringContainsString('max-w-md', $html, 'the member menu lost its phone cap');
        $this->assertStringNotContainsString('max-w-3xl', $html, 'the member menu was widened too');
    }

    /** The pairing is `md:`-only, so the phone keeps one column. */
    public function test_the_pairs_are_md_only(): void
    {
        $html = $this->applicationHtml();

        // Three new pairs, each a grid that only splits at md.
        $this->assertSame(3, substr_count($html, 'grid gap-3 md:grid-cols-2'), 'the md-only pairs changed in number');

        // …and the two that were ALWAYS two-up (name/surname, doc type/number) are untouched.
        $this->assertSame(2, substr_count($html, 'grid grid-cols-2 gap-3'), 'an existing always-two-up row changed');
    }

    /** Each field keeps its own error slot, so a message stays under the field it belongs to. */
    public function test_every_paired_field_keeps_its_own_error_slot(): void
    {
        // A real failed submit on a real token, then the form as the applicant sees it come back.
        $application = (new IssueApplicationInvite)->handle($this->manager, $this->location->id, null, 'errors');

        $html = (string) $this->from(route('socio.application', ['token' => $application->invite_token]))
            ->post(route('socio.application.store', ['token' => $application->invite_token]), [])
            ->assertRedirect()
            ->baseResponse->getContent();

        $html = (string) $this->followingRedirects()
            ->post(route('socio.application.store', ['token' => $application->invite_token]), [])
            ->getContent();

        foreach (['first_name', 'last_name', 'email', 'date_of_birth', 'document_type', 'document_number'] as $field) {
            $this->assertStringContainsString('id="'.$field.'-error"', $html, "{$field} lost its own error slot");
        }
    }
}
