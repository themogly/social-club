<?php

namespace Tests\Feature\Settings;

use App\Enums\SettingType;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 161 — the dead `organisations.settings` JSON column is retired (the org-level twin of
 * `locations.settings`, retired by prompt 59). Nothing read it, so nothing about behaviour changes; these pin
 * that, plus the migration's own contract: it drops a data-free column, refuses to drop one carrying content,
 * and `down()` restores it.
 */
class OrganisationSettingsRetirementTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_08_16_000000_retire_organisation_settings_json_column.php');
    }

    public function test_the_column_and_its_cast_are_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('organisations', 'settings'));
        $this->assertArrayNotHasKey('settings', (new Organisation)->getCasts());
    }

    public function test_down_restores_a_nullable_column_and_up_drops_it_again(): void
    {
        $m = $this->migration();

        $m->down();
        $this->assertTrue(Schema::hasColumn('organisations', 'settings'));

        // Nullable: an org can be created without touching it.
        $org = Organisation::factory()->create();
        $this->assertNull(DB::table('organisations')->where('id', $org->id)->value('settings'));

        $m->up();
        $this->assertFalse(Schema::hasColumn('organisations', 'settings'));
    }

    public function test_retiring_the_column_preserves_organisation_rows(): void
    {
        $m = $this->migration();
        $m->down(); // bring the column back so a drop can be proven to lose no rows

        $org = Organisation::factory()->create();
        $this->assertDatabaseHas('organisations', ['id' => $org->id]);

        $m->up(); // empty column → clean drop

        $this->assertFalse(Schema::hasColumn('organisations', 'settings'));
        $this->assertDatabaseHas('organisations', ['id' => $org->id]); // the org row survived the retirement
    }

    public function test_up_refuses_to_drop_when_a_row_carries_content(): void
    {
        $m = $this->migration();
        $m->down();

        $org = Organisation::factory()->create();
        DB::table('organisations')->where('id', $org->id)->update(['settings' => '{"undocumented":"blob"}']);

        // No key mapping exists for org.settings, so an undocumented blob is a finding — the migration fails
        // loudly rather than discarding Article-9-adjacent config.
        $this->expectException(RuntimeException::class);
        $m->up();
    }

    public function test_an_org_scoped_setting_resolves_identically_after_retirement(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        // The column was never part of Settings::get's resolution (location row → org row → DEFAULTS). Prove an
        // ORG-scoped key still resolves through the settings TABLE — the neighbourhood being touched.
        $default = (int) Settings::get('min_age');
        Settings::set('min_age', $default + 3, SettingType::INT);

        $this->assertSame($default + 3, (int) Settings::get('min_age')); // org row overrides the code default
    }
}
