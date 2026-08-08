<?php

namespace Tests\Browser;

use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 215 — the two application forms, for the side-by-side.
 *
 * The BEFORE is the argument: the applicant's form and the staff form, together, with the staff one missing
 * the photo, the ID document, the MRZ prefill and the declared monthly consumption.
 */
class ApplicationFormsHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_both_application_forms(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($location->id);

        $user = User::factory()->create(['name' => 'Lucía Márquez']);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($location, 'POS-1', 10000);

        // The staff form, open, inside the counter's chrome.
        $page = (string) $this->get(route('counter.members'))->assertOk()->getContent();
        $held = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->html();

        $open = (int) strpos($page, '<main');
        $close = (int) strrpos($page, '</main>');
        $staffPage = substr($page, 0, (int) strpos($page, '>', $open) + 1).$held.substr($page, $close);
        file_put_contents(storage_path('app/form-215-staff.html'), $this->inlineBuiltCss($staffPage));

        // The applicant's public form, at a real token.
        $application = (new IssueApplicationInvite)->handle($user, $location->id, null, 'harness');
        $public = (string) $this->get(route('socio.application', ['token' => $application->invite_token]))
            ->assertOk()->getContent();
        file_put_contents(storage_path('app/form-215-public.html'), $this->inlineBuiltCss($public));

        $this->assertStringContainsString('data-alta-staff-fields', $staffPage);
        $this->assertStringContainsString('name="document_scan"', $public);
    }
}
