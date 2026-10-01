<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\IssueMemberToken;
use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\Concerns\FindsMembers;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 332 — Ben, on Socios → Cobro de cuotas: "Won't let me use the camera. In the dispensary section the camera
 * works." The lookup partial showed the camera only when its host passed `cameraScanEnabled`, and only Recepción and
 * Dispensario did. The lookup now asks `FindsMembers::cameraScanEnabled()` itself — per sede — so every counter lookup
 * has it where the sede scans by camera, and a new screen gets it without remembering.
 */
class CameraOnEveryLookupTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        // Prompt 348 — these tests are about the LOOKUP; the photo check a scan now waits for is pinned in PhotoCheckOnScanTest.
        Settings::set('confirm_photo_on_scan', false, SettingType::BOOL);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->centro->id, $this->norte->id]);
        $this->actingAs($owner);
        CounterOperator::set($owner);
        foreach ([$this->centro, $this->norte] as $sede) {
            Settings::set('bar_attach_socio_enabled', true, SettingType::BOOL, $sede->id);
            (new OpenTill)->handle($sede, 'POS-1', 10000);
        }
        Settings::set('camera_scan_enabled', true, SettingType::BOOL, $this->centro->id);
        Settings::set('camera_scan_enabled', false, SettingType::BOOL, $this->norte->id);
        $this->at($this->centro);
    }

    private function at(Location $sede): void
    {
        session(['counter.location_id' => $sede->id]);
        app(ActiveScope::class)->setLocation($sede->id);
        Settings::flush();
    }

    /** @return array<string, int> how many camera triggers each screen's lookup renders */
    private function cameras(): array
    {
        $count = fn (string $screen): int => substr_count(Livewire::test($screen)->html(), 'data-camera-scan');

        return [
            'Recepción' => $count(CheckInScreen::class),
            'Socios' => $count(MembershipCounter::class),
            'Dispensario' => $count(DispensaryPos::class),
            'Barra' => $count(BarPos::class),
        ];
    }

    // --- 1–3. Every lookup, on and off, per sede --------------------------------------------------------------------------------

    public function test_every_counter_lookup_shows_the_camera_where_the_sede_scans_by_camera(): void
    {
        $this->assertSame(['Recepción' => 1, 'Socios' => 1, 'Dispensario' => 1, 'Barra' => 1], $this->cameras());
    }

    public function test_none_shows_it_where_the_sede_does_not(): void
    {
        Settings::set('camera_scan_enabled', false, SettingType::BOOL, $this->centro->id);
        $this->at($this->centro);

        $this->assertSame(['Recepción' => 0, 'Socios' => 0, 'Dispensario' => 0, 'Barra' => 0], $this->cameras());
    }

    public function test_the_setting_is_read_for_the_counters_own_sede(): void
    {
        $this->at($this->norte);
        $this->assertSame(['Recepción' => 0, 'Socios' => 0, 'Dispensario' => 0, 'Barra' => 0], $this->cameras());

        $this->at($this->centro);
        app(ActiveScope::class)->setLocation($this->norte->id); // the panel's scope elsewhere: the counter's sede decides
        Settings::flush();
        $this->assertSame(1, substr_count(Livewire::test(MembershipCounter::class)->html(), 'data-camera-scan'));
    }

    // --- 4. A scan does what the search does ------------------------------------------------------------------------------------------

    public function test_a_scanned_card_opens_the_fee_panel_on_socios_and_attaches_the_member_at_the_bar(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE]);
        $token = (new IssueMemberToken)->handle($member);

        Livewire::test(MembershipCounter::class)->call('submitCameraScan', $token)->assertSet('feeMemberId', $member->id);
        Livewire::test(BarPos::class)->call('submitCameraScan', $token)->assertSet('memberId', $member->id);
    }

    // --- 5. One source ------------------------------------------------------------------------------------------------------------------

    public function test_no_host_passes_the_flag_and_a_new_host_gets_the_camera_by_itself(): void
    {
        foreach ([...glob(app_path('Livewire/Counter/*.php')) ?: [], ...glob(resource_path('views/livewire/counter/{*,*/*}.blade.php'), GLOB_BRACE) ?: []] as $file) {
            $this->assertStringNotContainsString("'cameraScanEnabled'", (string) file_get_contents($file), basename($file).' passes the camera flag');
            $this->assertStringNotContainsString('$cameraScanEnabled', (string) file_get_contents($file), basename($file).' reads a camera view variable');
        }

        $this->assertSame(1, substr_count(Livewire::test(PlantedLookupHost::class)->html(), 'data-camera-scan'));
        $this->at($this->norte);
        $this->assertSame(0, substr_count(Livewire::test(PlantedLookupHost::class)->html(), 'data-camera-scan'));
    }
}

/** A screen nobody wrote yet: it includes the lookup and does nothing about the camera. */
class PlantedLookupHost extends Component
{
    use FindsMembers;

    public ?string $locationId = null;

    public function mount(): void
    {
        $this->locationId = session('counter.location_id');
    }

    public function hasOperator(): bool
    {
        return true;
    }

    public function userCan(string $permission): bool
    {
        return true;
    }

    private function flash(string $message, string $type): void {}

    protected function onMemberFound(Member $member, bool $scanned): void {}

    public function render(): string
    {
        return <<<'BLADE'
            <div>@include('livewire.counter.partials.member-lookup', ['autofocus' => false])</div>
            BLADE;
    }
}
