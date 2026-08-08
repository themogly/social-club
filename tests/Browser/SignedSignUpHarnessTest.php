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
 * Prompt 220 — the signature pad on every route that takes one.
 *
 * Two templates carry it for the sign-up (the third consumer, the dispensation POS, was shot by prompt 113),
 * and the applicant's one is shot at two sizes because the SAME page is both routes: an emailed link on the
 * person's own phone, and the club's tablet handed across the counter.
 *
 *   php artisan test tests/Browser/SignedSignUpHarnessTest.php   # → storage/app/signature-220-*.html
 *   node tests/Browser/shoot-signature-pad.mjs
 */
class SignedSignUpHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_signed_forms(): void
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

        // The applicant's own form, at a real token — the emailed link AND the handover are this page.
        $application = (new IssueApplicationInvite)->handle($user, $location->id, null, 'harness');
        $public = (string) $this->get(route('socio.application', ['token' => $application->invite_token]))
            ->assertOk()->getContent();
        file_put_contents(storage_path('app/signature-220-applicant.html'), $this->inlineBuiltCss($public));

        // The staff-typed form, open, inside the counter's chrome (prompt 215's composition).
        $page = (string) $this->get(route('counter.members'))->assertOk()->getContent();
        $held = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->html();

        $open = (int) strpos($page, '<main');
        $close = (int) strrpos($page, '</main>');
        $staffPage = substr($page, 0, (int) strpos($page, '>', $open) + 1).$held.substr($page, $close);
        file_put_contents(storage_path('app/signature-220-staff.html'), $this->inlineBuiltCss($staffPage));

        // And the CAPTURED state, server-rendered — the pad's stored branch, which is the only way to shoot the
        // confirmation panel without a browser running Livewire's Alpine.
        $captured = Livewire::test(MembershipCounter::class)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->call('saveAltaSignature', 'data:image/png;base64,'.base64_encode('drawn'))
            ->html();
        $capturedPage = substr($page, 0, (int) strpos($page, '>', $open) + 1).$captured.substr($page, $close);
        file_put_contents(storage_path('app/signature-220-staff-captured.html'), $this->inlineBuiltCss($capturedPage));
        $this->assertStringContainsString('data-signature-captured', $capturedPage, 'the captured state did not render');

        foreach (['applicant' => $public, 'staff' => $staffPage] as $route => $html) {
            $this->assertStringContainsString('data-signature-pad', $html, "the {$route} form has no signature pad");
            $this->assertStringContainsString('data-signature-canvas', $html, "the {$route} form's pad has no canvas");
        }
    }
}
