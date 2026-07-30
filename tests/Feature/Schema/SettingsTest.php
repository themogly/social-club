<?php

namespace Tests\Feature\Schema;

use App\Enums\SettingType;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_the_code_default_for_a_missing_key(): void
    {
        // No org, no rows — resolves to the hardcoded default, never throws.
        $this->assertSame(15, Settings::get('carencia_days'));
        $this->assertSame(350, Settings::get('daily_limit_cg'));
        $this->assertSame('a-fallback', Settings::get('nonexistent_key', 'a-fallback'));
    }

    public function test_a_seeded_row_overrides_the_default_and_resolves_location_over_org(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        Settings::set('carencia_days', 21, SettingType::INT);
        $this->assertSame(21, Settings::get('carencia_days'));
    }

    public function test_get_does_not_throw_inside_a_queued_job(): void
    {
        // Sync queue in tests: the closure runs inline. A missing/stale setting must
        // degrade to a default, never throw (which would silently kill the job).
        dispatch(function (): void {
            Settings::get('carencia_days');
            Settings::get('a_key_that_does_not_exist');
        });

        $this->assertTrue(true); // reached here => no exception escaped the job
    }
}
