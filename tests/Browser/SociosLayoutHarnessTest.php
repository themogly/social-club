<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
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
 * Prompt 210 — the Socios screen carrying its three jobs, for the layout pass.
 *
 * Written with a member ON SCREEN, because that is the state the width complaint is about: sign-up, fee
 * collection and the member's record were all stacked in one `max-w-xl` column, so the record began below the
 * fold at 1180×820 with the space unused on both sides.
 */
class SociosLayoutHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_socios_screen_with_a_member_on_it(): void
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

        $member = Member::factory()->create([
            'organisation_id' => $org->id,
            'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subDay(),
        ]);
        Membership::factory()->create([
            'organisation_id' => $org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $org->id])->id,
            'status' => MembershipStatus::ACTIVE,
            'starts_at' => now()->subMonths(6), 'expires_at' => now()->addMonths(6),
        ]);

        $page = (string) $this->get(route('counter.members'))->assertOk()->getContent();

        // With the socio HELD, which is the state the width complaint is about — a plain GET renders the
        // empty state, where there is no record to be pushed below the fold. Spliced in the same way prompt
        // 209's harness does: the real page, with the component's real rendered output in its <main>.
        $held = Livewire::test(MembershipCounter::class)
            ->call('selectMember', $member->id)
            ->html();

        $open = (int) strpos($page, '<main');
        $close = (int) strrpos($page, '</main>');
        $mainOpenEnd = (int) strpos($page, '>', $open);
        $page = substr($page, 0, $mainOpenEnd + 1).$held.substr($page, $close);

        file_put_contents(storage_path('app/socios-210.html'), $this->inlineBuiltCss($page));

        // Prompt 221 moved the sign-up off the page and onto a modal, so what this harness measures as
        // "the sign-up job" is now its ENTRANCE. Retargeted rather than deleted: the measurement — where each
        // of the three jobs starts, and whether the record is above the fold — is exactly as meaningful
        // afterwards, and 210's before/after only reads if the after keeps being taken.
        $this->assertStringContainsString('data-alta-toggle', $page);
        $this->assertStringContainsString('Cobro de cuota', $page);
        $this->assertStringContainsString('Ana Ruiz', $page, 'the harness has no member on screen');

        // …and the same screen with the staff-typed form open, which is the route prompt 210 adds.
        $withForm = Livewire::test(MembershipCounter::class)
            ->call('selectMember', $member->id)
            ->call('toggleAlta')
            ->call('toggleStaffAltaForm')
            ->html();

        $formPage = substr($page, 0, (int) strpos($page, '<main'));
        $formPage = (string) $this->get(route('counter.members'))->getContent();
        $open = (int) strpos($formPage, '<main');
        $close = (int) strrpos($formPage, '</main>');
        $formPage = substr($formPage, 0, (int) strpos($formPage, '>', $open) + 1).$withForm.substr($formPage, $close);

        file_put_contents(storage_path('app/socios-210-form.html'), $this->inlineBuiltCss($formPage));
        $this->assertStringContainsString('data-alta-staff-fields', $formPage, 'the staff form did not render');
    }
}
