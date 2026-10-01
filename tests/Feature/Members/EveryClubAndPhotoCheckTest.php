<?php

namespace Tests\Feature\Members;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Members\IssueMemberToken;
use App\Actions\Members\ResolveMemberByToken;
use App\Actions\Memberships\CancelMembership;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Memberships\RenewMembership;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\FeePaymentMethod;
use App\Enums\LocationKind;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Exceptions\DispensationBlockedException;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 348 — Aaron: "New member sign-up: auto sign up to all locations except storage… QR code handing around to people."
 * Ben: "Bring a picture up when they scan the QR code, and make it a requirement they send a pic."
 */
class EveryClubAndPhotoCheckTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private Location $sur;

    private Location $almacen;

    private User $owner;

    private MembershipTier $tier;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        $this->sur = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Sur']);
        $this->almacen = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Almacén', 'kind' => LocationKind::ALMACEN]);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->centro->id, $this->norte->id, $this->sur->id]);
        $this->actingAs($this->owner);
        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => 3000]);
    }

    private function member(array $attributes = []): Member
    {
        return Member::factory()->create($attributes + ['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 10000, 'monthly_limit_cg' => 100000]);
    }

    // --- 1–3. Every club ----------------------------------------------------------------------------------------------------

    public function test_enrolling_at_centro_enrols_every_sede_but_the_store_with_one_fee(): void
    {
        $member = $this->member();
        $home = (new EnrolMembership)->handle($member, $this->centro, $this->tier, ['actor' => $this->owner]);

        $all = Membership::query()->withoutGlobalScopes()->where('member_id', $member->id)->get();
        $this->assertEqualsCanonicalizing([$this->centro->id, $this->norte->id, $this->sur->id], $all->pluck('location_id')->all(), 'never the store');
        $this->assertSame(3000, $home->fee_cents->cents);
        foreach ($all->where('location_id', '!=', $this->centro->id) as $linked) {
            $this->assertSame($home->id, $linked->covered_by_id);
            $this->assertSame(0, $linked->fee_cents->cents);
            $this->assertSame((string) $home->expires_at, (string) $linked->expires_at);
            $this->assertSame($home->tier_id, $linked->tier_id);
        }

        // …and can be served at Sede Norte.
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->norte->id,
            'remaining_cg' => 5000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
        $dispensation = (new CommitDispensation)->handle($member, $this->norte, [['genetic_id' => $genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 100]], ['operator_id' => $this->owner->id]);
        $this->assertNotNull($dispensation->id);
    }

    public function test_the_linked_ones_renew_and_cancel_with_the_home_one_and_cannot_be_charged_alone(): void
    {
        $member = $this->member();
        $home = (new EnrolMembership)->handle($member, $this->centro, $this->tier, ['actor' => $this->owner]);
        $linked = Membership::query()->withoutGlobalScopes()->where('member_id', $member->id)->where('location_id', $this->norte->id)->sole();

        $this->travel(2)->days();
        $renewed = (new RenewMembership)->handle($home->fresh(), ['actor' => $this->owner]);
        $this->assertSame((string) $renewed->expires_at, (string) $linked->fresh()->expires_at);

        try {
            (new RecordFeePayment)->handle($linked->fresh(), 1000, FeePaymentMethod::CASH);
            $this->fail('a linked membership was charged on its own');
        } catch (RuntimeException $e) {
            $this->assertSame(__('Cubierta por la membresía de :sede', ['sede' => 'Sede Centro']), $e->getMessage());
        }
        try {
            (new RenewMembership)->handle($linked->fresh(), ['actor' => $this->owner]);
            $this->fail('a linked membership was renewed on its own');
        } catch (RuntimeException) {
        }

        (new CancelMembership)->handle($home->fresh(), $this->owner, 'Se va del club');
        $this->assertSame(MembershipStatus::CANCELLED, $linked->fresh()->status);
    }

    public function test_extend_memberships_to_a_new_sede_and_with_the_setting_off_enrolment_is_single_sede(): void
    {
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        $member = $this->member();
        (new EnrolMembership)->handle($member, $this->centro, $this->tier, ['actor' => $this->owner]);
        $this->assertSame(1, Membership::query()->withoutGlobalScopes()->where('member_id', $member->id)->count(), 'setting off: one sede, as before');

        $nueva = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Nueva']);
        $this->artisan('csc:extend-memberships-to', ['sede' => 'Sede Nueva'])->expectsOutputToContain('1')->assertSuccessful();

        $linked = Membership::query()->withoutGlobalScopes()->where('member_id', $member->id)->where('location_id', $nueva->id)->sole();
        $this->assertNotNull($linked->covered_by_id);
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'memberships.extended')->count());

        $this->artisan('csc:extend-memberships-to', ['--all-sedes' => true])->assertSuccessful();
        $this->assertSame(5 - 1, Membership::query()->withoutGlobalScopes()->where('member_id', $member->id)->count(), 'every sede but the store');
    }

    // --- 5. No photo, no dispensing -----------------------------------------------------------------------------------------------

    public function test_a_member_without_a_photo_is_blocked_with_hacer_foto_and_a_photo_unblocks(): void
    {
        $member = $this->member(['photo_path' => null]);
        (new EnrolMembership)->handle($member, $this->centro, $this->tier, ['actor' => $this->owner, 'fee_cents' => 3000]);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->centro->id,
            'remaining_cg' => 5000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
        $line = [['genetic_id' => $genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 100]];

        try {
            (new CommitDispensation)->handle($member, $this->centro, $line, ['operator_id' => $this->owner->id]);
            $this->fail('dispensed with no photo');
        } catch (DispensationBlockedException $e) {
            $this->assertSame(__('Hazle una foto antes de dispensar.'), $e->getMessage());
        }

        app(ActiveScope::class)->setLocation($this->centro->id);
        session(['counter.location_id' => $this->centro->id]);
        (new OpenTill)->handle($this->centro, 'POS-1', 10000);
        CounterOperator::set($this->owner);
        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)
            ->assertSeeHtml('data-blocked-resolution="photo"')->assertSee(__('Hazle una foto antes de dispensar'));

        $member->forceFill(['photo_path' => 'member-photos/new.jpg'])->save();
        $this->assertNotNull((new CommitDispensation)->handle($member->fresh(), $this->centro, $line, ['operator_id' => $this->owner->id])->id);
    }

    // --- 6. The photo check on a scan --------------------------------------------------------------------------------------------

    private function socios(): Testable
    {
        app(ActiveScope::class)->setLocation($this->centro->id);
        session(['counter.location_id' => $this->centro->id]);
        if (! TillSession::query()->withoutGlobalScopes()->where('location_id', $this->centro->id)->where('status', 'OPEN')->exists()) {
            (new OpenTill)->handle($this->centro, 'POS-1', 10000);
        }
        CounterOperator::set($this->owner);

        return Livewire::test(MembershipCounter::class);
    }

    public function test_a_scan_shows_the_photo_check_and_no_records_misuse_while_yes_selects(): void
    {
        $member = $this->member();
        $token = (new IssueMemberToken)->handle($member);

        $no = $this->socios()->set('lookup', $token)->call('submitLookup')
            ->assertSet('photoCheckMemberId', $member->id)->assertSeeHtml('data-photo-check')
            ->assertSet('feeMemberId', null)
            ->call('rejectPhotoCheck')
            ->assertSet('photoCheckMemberId', null)->assertSet('feeMemberId', null)
            ->assertSee(__('No se ha atendido. Avisa a un responsable.'));
        $this->assertSame(1, $member->fresh()->card_misuse_count);
        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'member.card_misuse_suspected')->sole();
        $this->assertSame($this->owner->id, $audit->after['operator_id'] ?? null);
        $this->assertSame($this->centro->id, $audit->after['location_id'] ?? null);

        $this->socios()->set('lookup', $token)->call('submitLookup')->call('confirmPhotoCheck')
            ->assertSet('feeMemberId', $member->id)->assertDontSeeHtml('data-photo-check');
    }

    public function test_typing_the_name_skips_the_photo_check(): void
    {
        $member = $this->member(['first_name' => 'Lucía', 'last_name' => 'Martín']);

        $this->socios()->call('selectMember', $member->id)
            ->assertSet('photoCheckMemberId', null)->assertSet('feeMemberId', $member->id);
    }

    // --- 7. Reissuing a card ---------------------------------------------------------------------------------------------------------

    public function test_reissuing_the_card_stops_the_old_qr_working(): void
    {
        $member = $this->member(['email' => null]);
        $old = (new IssueMemberToken)->handle($member);
        $this->assertSame($member->id, (new ResolveMemberByToken)->handle($old)?->id);

        $this->socios()->call('selectMember', $member->id)->call('reissueCard')
            ->assertSee(__('Carné reemitido: el anterior ya no funciona'));

        $this->assertNull((new ResolveMemberByToken)->handle($old));
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'member.card_reissued')->count());
    }
}
