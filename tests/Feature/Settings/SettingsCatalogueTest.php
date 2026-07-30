<?php

namespace Tests\Feature\Settings;

use App\Enums\SettingType;
use App\Models\Location;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private function org(): Organisation
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        return $org;
    }

    public function test_enforcement_matrix_defaults_and_fail_safe(): void
    {
        $this->org();

        $this->assertSame('BLOCK', Settings::enforcement('counter', 'daily_limit'));
        $this->assertSame('WARN', Settings::enforcement('door', 'debt'));
        $this->assertSame('BLOCK', Settings::enforcement('door', 'no_such_rule')); // fail safe
    }

    public function test_member_number_formatting_follows_settings(): void
    {
        $this->assertSame('M-00042', Settings::formatMemberNumber(42)); // defaults

        $this->org();
        Settings::set('member_number_prefix', 'SOC-');
        Settings::set('member_number_padding', 4, SettingType::INT);

        $this->assertSame('SOC-0042', Settings::formatMemberNumber(42));
    }

    public function test_location_override_beats_org_default(): void
    {
        $org = $this->org();
        $location = Location::factory()->create(['organisation_id' => $org->id]);

        Settings::set('carencia_days', 20, SettingType::INT);                       // org level
        Settings::set('carencia_days', 40, SettingType::INT, $location->id);        // location level

        app(ActiveScope::class)->setLocation($location->id);
        $this->assertSame(40, Settings::get('carencia_days'));

        app(ActiveScope::class)->setLocation(null);
        $this->assertSame(20, Settings::get('carencia_days'));
    }
}
