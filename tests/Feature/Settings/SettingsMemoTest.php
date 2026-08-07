<?php

namespace Tests\Feature\Settings;

use App\Enums\SettingType;
use App\Models\Location;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prompt 109 — Settings::get() memoises per RESOLVED (organisation, location, key). The multi-tenancy tests
 * come first: the scope is ambient and changes within a process, so a key-only cache would hand one club's
 * value to another. These prove it does not, which is what makes the memo safe to ship.
 */
class SettingsMemoTest extends TestCase
{
    use RefreshDatabase;

    private function scope(): ActiveScope
    {
        return app(ActiveScope::class);
    }

    public function test_two_organisations_never_share_a_memoised_value(): void
    {
        $a = Organisation::factory()->create();
        $b = Organisation::factory()->create();

        $this->scope()->setOrganisation($a->id);
        Settings::set('daily_limit_cg', 111, SettingType::INT);
        $this->scope()->setOrganisation($b->id);
        Settings::set('daily_limit_cg', 222, SettingType::INT);

        // Read alternately in ONE process — each org gets its own value.
        $this->scope()->setOrganisation($a->id);
        $this->assertSame(111, Settings::get('daily_limit_cg'));
        $this->scope()->setOrganisation($b->id);
        $this->assertSame(222, Settings::get('daily_limit_cg'));
        $this->scope()->setOrganisation($a->id);
        $this->assertSame(111, Settings::get('daily_limit_cg'));
    }

    public function test_two_locations_in_one_org_never_share_a_memoised_value(): void
    {
        $org = Organisation::factory()->create();
        $this->scope()->setOrganisation($org->id);
        $a = Location::factory()->create(['organisation_id' => $org->id]);
        $b = Location::factory()->create(['organisation_id' => $org->id]);

        Settings::set('daily_limit_cg', 333, SettingType::INT, $a->id);
        Settings::set('daily_limit_cg', 444, SettingType::INT, $b->id);

        $this->scope()->setLocation($a->id);
        $this->assertSame(333, Settings::get('daily_limit_cg'));
        $this->scope()->setLocation($b->id);
        $this->assertSame(444, Settings::get('daily_limit_cg'));
        $this->scope()->setLocation($a->id);
        $this->assertSame(333, Settings::get('daily_limit_cg'));
    }

    public function test_reading_one_key_ten_times_is_one_query(): void
    {
        $org = Organisation::factory()->create();
        $this->scope()->setOrganisation($org->id);

        DB::enableQueryLog();
        for ($i = 0; $i < 10; $i++) {
            Settings::get('daily_limit_cg');
        }
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $queries, "10 reads issued {$queries} queries — the memo is not working.");
    }

    public function test_a_write_is_immediately_visible_to_the_next_get(): void
    {
        $org = Organisation::factory()->create();
        $this->scope()->setOrganisation($org->id);

        $this->assertSame(Settings::DEFAULTS['daily_limit_cg'], Settings::get('daily_limit_cg')); // memoises the default
        Settings::set('daily_limit_cg', 999, SettingType::INT);                                    // clears the memo
        $this->assertSame(999, Settings::get('daily_limit_cg'));                                   // the override, not the stale memo
    }

    public function test_a_location_override_set_after_reading_the_org_value_wins(): void
    {
        $org = Organisation::factory()->create();
        $this->scope()->setOrganisation($org->id);
        Settings::set('daily_limit_cg', 500, SettingType::INT); // org-level
        $loc = Location::factory()->create(['organisation_id' => $org->id]);
        $this->scope()->setLocation($loc->id);

        $this->assertSame(500, Settings::get('daily_limit_cg')); // reads (and memoises) the org value for this scope
        Settings::set('daily_limit_cg', 700, SettingType::INT, $loc->id); // location override clears the memo
        $this->assertSame(700, Settings::get('daily_limit_cg')); // the override, not the memo
    }

    /**
     * An org value is the SAME value whether or not a location is in scope — unless that location genuinely
     * has an override (prompt 197).
     *
     * The memo key is `organisation|location|key`, so the same key read with and without a location in scope
     * is two different memo entries and two different queries. That is correct and deliberate, and it is
     * also a place where a caller could quietly get a different answer from the one a test set up: a test
     * reads a setting with no session and no location, while the code under test reads it inside a request
     * that may have resolved one. Different key, fresh query — and if the two ever disagreed, the symptom
     * would be a feature silently behaving as though it were configured differently.
     *
     * They must not disagree, and this pins that.
     */
    public function test_an_org_value_is_the_same_with_and_without_a_location_in_scope(): void
    {
        $org = Organisation::factory()->create();
        $this->scope()->setOrganisation($org->id);
        Settings::set('temporary_members_enabled', true, SettingType::BOOL); // org-level, no location

        $withoutLocation = Settings::get('temporary_members_enabled');
        $this->assertTrue($withoutLocation);

        $loc = Location::factory()->create(['organisation_id' => $org->id]);
        $this->scope()->setLocation($loc->id);

        $this->assertSame(
            $withoutLocation,
            Settings::get('temporary_members_enabled'),
            'a location in scope must not change an org-level value when that location has no override',
        );

        // And back again, in the same process — the two memo entries agree in both directions.
        $this->scope()->setLocation(null);
        $this->assertSame($withoutLocation, Settings::get('temporary_members_enabled'));

        // The ONE case where they legitimately differ: a real location-scoped row.
        Settings::set('temporary_members_enabled', false, SettingType::BOOL, $loc->id);
        $this->scope()->setLocation($loc->id);
        $this->assertFalse(Settings::get('temporary_members_enabled'), 'a genuine override still wins');
        $this->scope()->setLocation(null);
        $this->assertTrue(Settings::get('temporary_members_enabled'), 'and does not leak back to the org scope');
    }

    public function test_a_missing_key_returns_the_callers_default_and_does_not_memoise_it(): void
    {
        $org = Organisation::factory()->create();
        $this->scope()->setOrganisation($org->id);

        // A key absent from DEFAULTS with no row resolves to the caller's $default, which varies per call —
        // so it must NOT be memoised (or the second call would wrongly return the first call's default).
        $this->assertSame('x', Settings::get('__phantom_key__', 'x'));
        $this->assertSame('y', Settings::get('__phantom_key__', 'y'));
    }
}
