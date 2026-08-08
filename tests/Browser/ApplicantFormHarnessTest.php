<?php

namespace Tests\Browser;

use App\Actions\Members\IssueApplicationInvite;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\MrzPrefill;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 217 — the applicant's form at PHONE size, in both of its states.
 *
 * Two files, because the second state is the one my earlier pass could not reach: prompt 179's MRZ confirm
 * chips render only **after** a successful document read, so a static page load never shows them and a
 * measurement of a static load never measures them.
 */
class ApplicantFormHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_form_in_both_states(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($location->id);

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$location->id]);

        $application = (new IssueApplicationInvite)->handle($manager, $location->id, null, 'harness');
        $token = (string) $application->invite_token;
        $url = route('socio.application', ['token' => $token]);

        // 1) As an applicant first opens it.
        file_put_contents(
            storage_path('app/applicant-217-initial.html'),
            $this->inlineBuiltCss((string) $this->get($url)->assertOk()->getContent()),
        );

        // 2) After a successful document read — the state that only exists once 179's reader has run, and
        //    which a static page load can never produce. Seeded through `MrzPrefill`, which is exactly what
        //    the reader's POST leaves behind.
        MrzPrefill::remember($token, [
            'first_name' => 'ANNA MARIA',
            'last_name' => 'ERIKSSON',
            'document_number' => 'L898902C3',
            'date_of_birth' => '1974-08-12',
        ]);

        $scanned = (string) $this->get($url)->assertOk()->getContent();
        file_put_contents(storage_path('app/applicant-217-scanned.html'), $this->inlineBuiltCss($scanned));

        $this->assertStringContainsString('data-mrz-prefilled', $scanned, 'the post-scan state did not render');
        $this->assertSame(4, substr_count($scanned, 'data-mrz-confirm='), 'expected a confirm chip per prefilled field');
    }
}
