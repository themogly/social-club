<?php

namespace Tests\Browser;

use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 185 — writes the member menu with all three availability states, in each locale, for the phone-
 * width screenshot pass (`node tests/Browser/shoot-menu-availability.mjs`).
 *
 * Doubles as the CI structural check: the three states render, and NO gram figure reaches the response.
 */
class MenuAvailabilityHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_the_menu_in_both_locales(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);

        $member = Member::factory()->create(['organisation_id' => $org->id, 'status' => MemberStatus::ACTIVE]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $org->id]);
        Membership::factory()->create([
            'organisation_id' => $org->id, 'member_id' => $member->id, 'location_id' => $location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        foreach ([['Amnesia Haze', 50000], ['Critical Kush', 1000], ['Lemon Skunk', 0]] as [$name, $cg]) {
            $genetic = Genetic::factory()->create([
                'organisation_id' => $org->id, 'name' => $name, 'active' => true, 'published' => true,
            ]);
            GeneticPrice::factory()->create([
                'organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                'tier_id' => null, 'price_per_gram_cents' => 900, 'active' => true, 'low_stock_threshold_cg' => null,
            ]);
            Batch::factory()->create([
                'organisation_id' => $org->id, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                'initial_cg' => max($cg, 1), 'remaining_cg' => $cg, 'initial_units' => null, 'remaining_units' => null,
                'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
            ]);
        }

        foreach (['es', 'en'] as $locale) {
            // The socio switcher's in-session override — see the README; setting the app locale does nothing.
            $html = $this->actingAs($member, 'member')->withSession(['locale' => $locale])
                ->get(route('socio.menu'))->getContent();

            $css = '';
            foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
                $css .= (string) file_get_contents($file);
            }

            if ($css !== '') {
                $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
                $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
            }

            file_put_contents(storage_path('app/menu-'.$locale.'.html'), $html);

            foreach (['available', 'low', 'unavailable'] as $state) {
                $this->assertStringContainsString('data-availability="'.$state.'"', $html, "$locale: no $state state");
            }

            // The negative that matters, asserted per locale on the raw body.
            foreach (['50000', '500.00', 'remaining_cg'] as $leak) {
                $this->assertStringNotContainsString($leak, $html, "$locale: the menu leaks holdings via \"$leak\"");
            }
        }
    }
}
