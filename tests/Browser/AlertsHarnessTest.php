<?php

namespace Tests\Browser;

use App\Enums\DashboardAlert;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 207 — the rail with alerts on it, and the screen each one lands on with the subject visible.
 *
 * Three pages, because the whole claim of the branch is that the second and third exist: the hub says *how
 * many* and never *who*, and the arrival says *who*.
 */
class AlertsHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_rail_and_the_screens_it_lands_on(): void
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

        $tier = MembershipTier::factory()->create(['organisation_id' => $org->id]);

        foreach ([['Ana', 'Ruiz', 6], ['Carlos', 'Vidal', 12], ['Marta', 'Nieto', 25]] as [$first, $last, $days]) {
            $member = Member::factory()->create([
                'organisation_id' => $org->id,
                'first_name' => $first, 'last_name' => $last,
                'status' => MemberStatus::ACTIVE,
                'date_of_birth' => now()->subYears(34),
                'carencia_ends_at' => now()->subDay(),
            ]);
            Membership::factory()->create([
                'organisation_id' => $org->id, 'member_id' => $member->id,
                'location_id' => $location->id, 'tier_id' => $tier->id,
                'status' => MembershipStatus::ACTIVE,
                'starts_at' => now()->subYear(), 'expires_at' => now()->addDays($days),
            ]);
        }

        MemberApplication::factory()->submitted()->create([
            'organisation_id' => $org->id, 'location_id' => $location->id,
            'payload' => ['first_name' => 'Bruno', 'last_name' => 'Sáez'],
        ]);

        $pages = [
            'hub' => route('counter.home'),
            'expiring' => route('counter.members', ['alert' => DashboardAlert::MEMBERSHIPS_EXPIRING->value]),
            'applications' => route('counter.members', ['alert' => DashboardAlert::PENDING_APPLICATIONS->value]),
        ];

        foreach ($pages as $name => $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            file_put_contents(storage_path('app/alerts-207-'.$name.'.html'), $this->inlineBuiltCss($html));
        }

        // The pictures are only worth taking if they show the thing: the hub counts, the arrivals name.
        $hub = (string) file_get_contents(storage_path('app/alerts-207-hub.html'));
        $this->assertStringContainsString('data-alert="memberships_expiring"', $hub);
        $this->assertStringNotContainsString('Ana', $hub, 'the hub named a member');

        $this->assertStringContainsString('Ana Ruiz', (string) file_get_contents(storage_path('app/alerts-207-expiring.html')));
        $this->assertStringContainsString('Bruno', (string) file_get_contents(storage_path('app/alerts-207-applications.html')));
    }
}
