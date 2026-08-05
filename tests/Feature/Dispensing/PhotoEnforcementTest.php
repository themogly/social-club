<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Attendance\ResolveMemberEligibility;
use App\Actions\Dispensing\CommitDispensation;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Exceptions\DispensationBlockedException;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 157 — photo-on-file enforcement at the counter. The rule DEFAULTS to OFF (no surprise block on
 * upgrade), can WARN (dispense proceeds, warning shown) or OVERRIDE (blocked unless a manager forces it with
 * a reason + audit), and NEVER hard-blocks. A member WITH a photo is unaffected. A legacy enforcement matrix
 * with no photo key resolves to OFF, not the fail-safe BLOCK. The door never enforces it.
 */
class PhotoEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null,
            'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $this->batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);
    }

    private function member(?string $photoPath = null): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'carencia_ends_at' => now()->subDay(), 'photo_path' => $photoPath,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        return $member;
    }

    private function setPhotoMode(string $mode): void
    {
        $matrix = Settings::DEFAULTS['enforcement'];
        $matrix['counter']['photo'] = $mode;
        Settings::set('enforcement', $matrix, SettingType::JSON);
    }

    /** @param array<string,mixed> $options */
    private function commit(Member $member, array $options = []): Dispensation
    {
        return (new CommitDispensation)->handle(
            $member, $this->location,
            [['genetic_id' => $this->genetic->id, 'batch_id' => $this->batch->id, 'grams_cg' => 100]],
            $options,
        );
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value); // holds limits.override
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    /** @return list<string> */
    private function photoRules(ResolveMemberEligibility $r, Member $member, string $surface): array
    {
        $verdict = $r->handle($member, $this->location, $surface);

        return array_values(array_filter($verdict->rules, fn (array $rule): bool => $rule['rule'] === 'photo'));
    }

    // --- Default: nothing configured, nothing changes -------------------------------

    public function test_with_no_rule_configured_a_member_without_a_photo_dispenses_normally(): void
    {
        $member = $this->member(photoPath: null);

        $this->assertNotNull($this->commit($member)->id);

        // The verdict carries NO photo rule when the club never opted in — byte-identical to before 157.
        $this->assertSame([], $this->photoRules(new ResolveMemberEligibility, $member, 'counter'));
    }

    public function test_a_legacy_enforcement_matrix_without_a_photo_key_does_not_block(): void
    {
        // A club that customised its matrix BEFORE 157 existed: a stored matrix with no `photo` key. It must
        // resolve to OFF, never the fail-safe BLOCK that enforcement() would return for an unknown rule.
        $matrix = Settings::DEFAULTS['enforcement'];
        unset($matrix['counter']['photo']);
        Settings::set('enforcement', $matrix, SettingType::JSON);

        $this->assertSame('OFF', Settings::photoEnforcement('counter'));
        $this->assertNotNull($this->commit($this->member(photoPath: null))->id);
    }

    // --- WARN: proceeds, warns ------------------------------------------------------

    public function test_warn_mode_allows_dispensing_and_surfaces_a_warning(): void
    {
        $this->setPhotoMode('WARN');
        $member = $this->member(photoPath: null);

        $this->assertNotNull($this->commit($member)->id); // not blocked

        $verdict = (new ResolveMemberEligibility)->handle($member, $this->location, 'counter');
        $this->assertContains('photo', array_map(fn (array $r): string => $r['rule'], $verdict->warnings()));
    }

    // --- OVERRIDE: blocked unless a manager forces it -------------------------------

    public function test_override_mode_blocks_a_photoless_member_without_an_override(): void
    {
        $this->setPhotoMode('OVERRIDE');

        $this->expectException(DispensationBlockedException::class);
        $this->commit($this->member(photoPath: null));
    }

    public function test_override_mode_allows_with_a_reason_and_writes_an_audit_row(): void
    {
        $this->setPhotoMode('OVERRIDE');
        $manager = $this->manager();
        $member = $this->member(photoPath: null);

        $dispensation = $this->commit($member, [
            'override' => true, 'override_by' => $manager, 'override_reason' => 'Socio de papel en migración',
        ]);

        $this->assertNotNull($dispensation->id);

        $audit = AuditLog::query()->where('action', 'dispensation.photo.override')->first();
        $this->assertNotNull($audit);
        $this->assertSame($manager->id, $audit->after['authorised_by']);
        $this->assertSame('Socio de papel en migración', $audit->after['reason']);
    }

    public function test_override_mode_does_not_affect_a_member_with_a_photo(): void
    {
        $this->setPhotoMode('OVERRIDE');
        $member = $this->member(photoPath: 'member-photos/has-one.jpg');

        $this->assertNotNull($this->commit($member)->id); // present → no block, no override needed

        $photoRule = $this->photoRules(new ResolveMemberEligibility, $member, 'counter')[0] ?? null;
        $this->assertNotNull($photoRule);
        $this->assertTrue($photoRule['satisfied']);
    }

    public function test_the_door_never_enforces_a_photo(): void
    {
        $this->setPhotoMode('OVERRIDE'); // strictest counter setting
        $member = $this->member(photoPath: null);

        // The door verdict carries no photo rule at all — the door is where the photo gets TAKEN.
        $this->assertSame([], $this->photoRules(new ResolveMemberEligibility, $member, 'door'));
    }
}
