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
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 177 — writes the Socios member record in its three states to storage/app/record-*.html for the
 * screenshot pass (`node tests/Browser/shoot-member-record.mjs`).
 *
 * Counter screen, so the CSS rule is app-*.css only (see the README) and the layout params the real page
 * passes must be passed here too.
 */
class MemberRecordHarnessTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
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

        file_put_contents(storage_path('app/record-'.$name.'.html'), $html);
    }

    public function test_it_writes_the_member_record_in_its_three_states(): void
    {
        $user = User::factory()->create(['name' => 'Marta Ruiz']);
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'General']);

        $make = function (string $first, string $last, int $feeCents, bool $withMembership) use ($tier): Member {
            $member = Member::factory()->create([
                'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
                'first_name' => $first, 'last_name' => $last,
                'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
                'daily_limit_cg' => 500, 'monthly_limit_cg' => 10000,
            ]);

            if ($withMembership) {
                Membership::factory()->create([
                    'organisation_id' => $this->org->id, 'member_id' => $member->id,
                    'location_id' => $this->location->id, 'tier_id' => $tier->id,
                    'status' => MembershipStatus::ACTIVE, 'fee_cents' => $feeCents,
                    'expires_at' => now()->addMonths(3),
                ]);
            }

            return $member;
        };

        // 1) active membership, nothing owed, history open
        $active = $make('Lucía', 'García', 0, true);
        $this->write('active', Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $active->id)->call('toggleHistory')->html());

        // 2) owes a fee
        $owing = $make('Álvaro', 'Ruiz', 2500, true);
        $this->write('owing', Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $owing->id)->html());

        // 3) none of the above — no membership at all
        $bare = $make('Nuria', 'Sanz', 0, false);
        $this->write('bare', Livewire::test(MembershipCounter::class)
            ->call('selectFeeMember', $bare->id)->html());

        foreach (['active', 'owing', 'bare'] as $state) {
            $html = (string) file_get_contents(storage_path('app/record-'.$state.'.html'));
            $this->assertStringContainsString('data-member-record', $html, "$state has no record panel");
            // Article 9 stays off the counter in every state.
            $this->assertStringNotContainsString('member-id-scans', $html, "$state leaks a scan path");
        }
    }
}
