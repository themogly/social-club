<?php

namespace Tests\Browser;

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
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 203 — writes the dead end and its three remedies to storage/app/deadend-*.html for the screenshot
 * pass (`node tests/Browser/shoot-membership-deadend.mjs`).
 *
 * The BEFORE artifact is the owner's screenshot reproduced: an ACTIVE member reading *"Sin membresía activa
 * en esta sede"*, with the verdict below telling the operator to renew from a record they cannot reach, and
 * **nothing on the screen to press**. Regenerate it by restoring `main`'s blade first — see the README.
 *
 * Counter screen, so the CSS rule is app-*.css only (see the README) and the layout params the real page
 * passes must be passed here too.
 */
class MembershipDeadEndHarnessTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Location $otherSede;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->otherSede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
    }

    private function write(string $name, string $componentHtml): void
    {
        $html = view('components.layouts.counter', [
            'slot' => new HtmlString($componentHtml),
            'title' => 'Socios',
        ])->render();

        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }

        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        file_put_contents(storage_path('app/deadend-'.$name.'.html'), $html);
    }

    public function test_it_writes_the_dead_end_and_its_three_remedies(): void
    {
        // STAFF deliberately: the whole point is what the person alone in the club on a Friday can do.
        $user = User::factory()->create(['name' => 'Marta Ruiz']);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);

        $tier = MembershipTier::factory()->create([
            'organisation_id' => $this->org->id, 'name' => 'General', 'default_fee_cents' => 2500,
        ]);

        $make = function (string $first, string $last, string $no): Member {
            return Member::factory()->create([
                'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
                'first_name' => $first, 'last_name' => $last, 'member_no' => $no,
                'date_of_birth' => now()->subYears(34), 'joined_at' => now()->subYears(2),
                'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 500, 'monthly_limit_cg' => 10000,
            ]);
        };

        // 1) The owner's case: lapsed here. On main this is the dead end; on the branch it renews.
        $lapsed = $make('Caitlin', 'Allen', 'M-00012');
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $lapsed->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::LAPSED, 'fee_cents' => 2500,
            'expires_at' => now()->subMonth(),
        ]);
        $this->write('lapsed', Livewire::test(MembershipCounter::class)->call('selectFeeMember', $lapsed->id)->html());

        // 2) Never enrolled anywhere — the tier picker.
        $bare = $make('Nuria', 'Sanz', 'M-00013');
        $this->write('none', Livewire::test(MembershipCounter::class)->call('selectFeeMember', $bare->id)->html());

        // 3) Active at the other sede: enrolled here as a SECOND membership, Norte untouched.
        $elsewhere = $make('Álvaro', 'Ruiz', 'M-00014');
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $elsewhere->id, 'location_id' => $this->otherSede->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 2500,
            'expires_at' => now()->addYear(),
        ]);
        $this->write('elsewhere', Livewire::test(MembershipCounter::class)->call('selectFeeMember', $elsewhere->id)->html());

        // The structural half, which runs in composer check while the pixels do not.
        foreach (['lapsed', 'none', 'elsewhere'] as $state) {
            $html = (string) file_get_contents(storage_path('app/deadend-'.$state.'.html'));
            $this->assertStringContainsString('data-membership-fix', $html, "$state offers no way out");
            $this->assertStringNotContainsString('member-id-scans', $html, "$state leaks a scan path");
        }

        $this->assertStringContainsString('data-membership-renew', (string) file_get_contents(storage_path('app/deadend-lapsed.html')));
        $this->assertStringContainsString('data-membership-tier', (string) file_get_contents(storage_path('app/deadend-none.html')));
        $this->assertStringContainsString('Sede Norte', (string) file_get_contents(storage_path('app/deadend-elsewhere.html')));
    }
}
