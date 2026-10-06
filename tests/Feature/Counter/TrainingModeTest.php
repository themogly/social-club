<?php

namespace Tests\Feature\Counter;

use App\Actions\Stock\IntakeArticle;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\OrganisationLockdown;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterBasket;
use App\Support\CounterOperator;
use App\Support\Settings;
use App\Support\TrainingMode;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 324 — *Modo formación*: staff practise on the real counter (real members, prices, limits, stock checks and
 * screens) and nothing they do is kept. ONE mechanism: while training is on, every counter request runs inside a
 * database transaction that is always rolled back, and the side effects a rollback can't undo (queue, mail, files,
 * cache, Telegram) are switched off for that request. Only `counter.training.started` / `.ended` are kept.
 *
 * Practice runs on the real state around it (Ben, 324): each step is rolled back on its own, so a step that needs an
 * earlier PRACTICE write (void a practice sale) gets the real refusal — nothing is saved either way.
 *
 * Driven over HTTP (the wrapper is middleware; `Livewire::test()` never passes through it).
 */
class TrainingModeTest extends TestCase
{
    use PostsLivewireOverHttp, RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $owner;

    private Member $member;

    /** A second socio with a fee to pay: the collect and the waiver (the first must be free to dispense). */
    private Member $feeMember;

    private Genetic $genetic;

    private Batch $batch;

    private MembershipTier $tier;

    private MemberApplication $application;

    private Article $agua;

    /** Tables whose rows are the platform's own plumbing, not the club's records. */
    private const PLUMBING = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'audit_logs'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        // Prompt 348 — the training member has no photo on purpose (uploads are refused in practice); training is not the
        // photo requirement's subject.
        Settings::set('require_photo_to_dispense', false, SettingType::BOOL);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        app(ActiveScope::class)->setLocation($this->centro->id);
        Settings::set('multiple_tills_enabled', true, SettingType::BOOL, $this->centro->id);
        Settings::set('signature_on_application', false, SettingType::BOOL); // the paper attestation instead of a drawn signature

        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->centro->id, $this->norte->id]);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        GeneticPrice::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->centro->id, 'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true]);
        $this->batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->centro->id, 'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000]);
        $this->agua = (new IntakeArticle)->handle(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'name' => 'Agua', 'price_cents' => 150, 'active' => true], 20);

        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000, 'photo_path' => null, 'debt_limit_cents' => 10000]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);
        $this->feeMember = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'carencia_ends_at' => now()->subMonth(), 'photo_path' => null]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $this->feeMember->id, 'location_id' => $this->centro->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 2000]);

        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $this->application = MemberApplication::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'submitted_at' => now(), 'payload' => [
            'first_name' => 'Marcos', 'last_name' => 'Espera', 'email' => 'marcos@club.test', 'date_of_birth' => '1988-02-03', 'document_type' => 'DNI', 'document_number' => '87654321X',
        ]]);

        (new OpenTill)->handle($this->centro, 'POS-1', 10000);

        // The tablet: signed in, sede chosen, the owner at the PIN.
        $this->actingAs($this->owner);
        $this->post(route('counter.location'), ['location_id' => $this->centro->id])->assertRedirect();
        CounterOperator::set($this->owner);
    }

    /** @return array<string, array{count: int, sum: string}> every club table's row count and checksum */
    private function fingerprint(): array
    {
        $out = [];
        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            if (in_array($table, self::PLUMBING, true)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn (object $row): array => (array) $row)->sortBy(fn (array $row): string => json_encode($row))->values();
            $out[$table] = ['count' => $rows->count(), 'sum' => md5((string) json_encode($rows))];
        }

        return $out;
    }

    private function enter(): void
    {
        $this->post(route('counter.training.start'))->assertRedirect();
        $this->assertTrue(TrainingMode::active(), 'training did not start');
    }

    /** One practice (or real) counter session, step by step as the tablet sends it. Returns the POS commit's response HTML. */
    private function counterSession(): string
    {
        // Check a member in.
        $this->livewirePost($this->snapshotFrom('/counter/checkin', 'counter.check-in-screen'), [], [['selectMember', [$this->member->id]], ['checkIn']])->assertOk();

        // A combined visit: flower + a bar item, paid in cash.
        $pos = $this->livewirePost($this->snapshotFrom('/counter/pos', 'counter.dispensary-pos'), ['cashTendered' => '100'],
            [['selectMember', [$this->member->id]], ['chooseGenetic', [$this->genetic->id]], ['addLine', ['2']], ['addBarItem', [$this->agua->id]], ['commitDispensation']])->assertOk();
        $html = (string) json_encode($pos->json(), JSON_UNESCAPED_UNICODE);

        // Then one on the tab, and a void of the last sale.
        $this->livewirePost($this->snapshotFrom('/counter/pos', 'counter.dispensary-pos'), [],
            [['selectMember', [$this->member->id]], ['chooseGenetic', [$this->genetic->id]], ['addLine', ['1']], ['commitOnTab']])->assertOk();
        $snapshot = (string) ($pos->json('components.0.snapshot') ?? '');
        if ($snapshot !== '') {
            $this->livewirePost($snapshot, ['voidReason' => 'Error de prueba'], [['voidLast']])->assertOk();
        }

        // A fee collected, and a waiver.
        $this->livewirePost($this->snapshotFrom('/counter/members', 'counter.membership-counter'), ['feeAmount' => '20'], [['selectMember', [$this->feeMember->id]], ['collectFee']])->assertOk();
        $waive = $this->livewirePost($this->snapshotFrom('/counter/members', 'counter.membership-counter'), [], [['selectMember', [$this->feeMember->id]], ['toggleWaive']])->assertOk();
        $this->livewirePost((string) $waive->json('components.0.snapshot'), ['waiveReason' => 'OTHER', 'waiveReasonText' => 'Prueba'], [['waiveFee']])->assertOk();

        // A sign-up typed at the counter, and the approval of a waiting application.
        $alta = $this->livewirePost($this->snapshotFrom('/counter/members', 'counter.membership-counter'), [], [['toggleAlta'], ['toggleStaffAltaForm']])->assertOk();
        $this->livewirePost((string) $alta->json('components.0.snapshot'), [
            'altaForm.first_name' => 'Lucía', 'altaForm.last_name' => 'Práctica', 'altaForm.email' => 'lucia@club.test', 'altaForm.date_of_birth' => '1990-05-01',
            'altaForm.document_type' => 'DNI', 'altaForm.document_number' => '12345678Z', 'altaConsentHeld' => true,
        ], [['submitStaffAlta']])->assertOk();
        $review = $this->livewirePost($this->snapshotFrom('/counter/members', 'counter.membership-counter'), [], [['reviewAltaApplication', [$this->application->id]]])->assertOk();
        $this->livewirePost((string) $review->json('components.0.snapshot'), ['altaTierId' => $this->tier->id], [['approveAlta']])->assertOk();

        // Clock in and out; a till movement; a second till opened; the first one closed.
        $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'), [], [['clockInNow']])->assertOk();
        $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'), ['movementType' => 'IN', 'movementAmount' => '5', 'movementReason' => 'Cambio'], [['recordMovement']])->assertOk();
        $close = $this->livewirePost($this->snapshotFrom('/counter/till', 'counter.till-session'), [], [['startClose']])->assertOk();
        $this->livewirePost((string) $close->json('components.0.snapshot'), [
            "reweighNotCounted.{$this->batch->id}" => true, 'countInput' => '110', 'closeNote' => 'Prueba',
        ], [['submitReweigh', ['JAR_UNAVAILABLE']], ['submitCount'], ['finishClose']])->assertOk(); // 360 — one answer for the count

        return $html;
    }

    // --- 1. The guarantee ----------------------------------------------------------------------------------------------------

    public function test_a_whole_practice_session_leaves_every_club_table_exactly_as_it_was(): void
    {
        // The REAL database queue, not Queue::fake(): the fake records a push on any connection, so it cannot see that a
        // practice request's jobs go to the null `training` connection. Nothing queued = the jobs table stays empty.
        config(['queue.default' => 'database']);
        Storage::fake('documents');
        Http::fake();
        $this->enter();
        $before = $this->fingerprint();
        $auditsBefore = AuditLog::query()->count();

        $this->counterSession();
        $this->post(route('counter.training.leave'))->assertRedirect();

        $this->assertSame($before, $this->fingerprint(), 'a practice write was kept');
        $this->assertSame(0, DB::table('jobs')->count(), 'a practice request queued a job');
        $this->assertSame([], Storage::disk('documents')->allFiles());
        Http::assertNothingSent();
        $this->assertSame(['counter.training.ended'], AuditLog::query()->latest('created_at')->orderByDesc('id')->limit(AuditLog::query()->count() - $auditsBefore)->pluck('action')->all(),
            'only the training entries may be written');
        $this->assertSame(1, AuditLog::query()->where('action', 'counter.training.started')->count());
        $this->assertFalse(TrainingMode::active());
    }

    // --- 2. The same session outside training writes (a pin) ----------------------------------------------------------------

    public function test_the_same_session_outside_training_is_recorded(): void
    {
        $before = $this->fingerprint();

        $this->counterSession();

        $after = $this->fingerprint();
        foreach (['check_ins', 'dispensations', 'orders', 'cash_movements', 'membership_fee_payments', 'member_applications', 'members', 'staff_clock_events', 'stock_takes', 'till_sessions'] as $table) {
            $this->assertNotSame($before[$table] ?? null, $after[$table] ?? null, "the real session wrote nothing to {$table}");
        }
    }

    // --- 3. What staff see ---------------------------------------------------------------------------------------------------

    /** The POS commit of 2 g + an agua, and what its settled outcome shows: [html, total]. */
    private function commitVisit(): array
    {
        $html = (string) $this->livewirePost($this->snapshotFrom('/counter/pos', 'counter.dispensary-pos'), ['cashTendered' => '100'],
            [['selectMember', [$this->member->id]], ['chooseGenetic', [$this->genetic->id]], ['addLine', ['2']], ['addBarItem', [$this->agua->id]], ['commitDispensation']])
            ->assertOk()->json('components.0.effects.html');
        // The last-sale line carries the figures ("Última: 21.50 € · 2.00 g · 14:02"); the time is dropped.
        preg_match('/data-last-sale-summary title="([^"]+)"/u', $html, $summary);

        return [$html, (string) preg_replace('/ · \d{1,2}:\d{2}$/', '', html_entity_decode($summary[1] ?? ''))];
    }

    public function test_a_practice_commit_shows_the_normal_success_with_practica_and_the_real_total(): void
    {
        $this->enter();
        [$practice, $practiceTotal] = $this->commitVisit();
        $this->post(route('counter.training.leave'));
        [$real, $realTotal] = $this->commitVisit();

        $this->assertStringContainsString('data-practice-suffix', $practice);
        $this->assertStringContainsString(__('(práctica)'), $practice);
        $this->assertStringNotContainsString('data-practice-suffix', $real);
        $this->assertNotSame('', $realTotal);
        $this->assertSame($realTotal, $practiceTotal, 'practice priced the visit differently from the real thing');
    }

    // --- 4. Entering is refused --------------------------------------------------------------------------------------------------

    public function test_entering_is_refused_with_a_basket_or_where_the_sede_does_not_allow_it(): void
    {
        CounterBasket::put('pos', $this->centro->id, [['type' => 'genetic', 'id' => $this->genetic->id]]);
        $this->post(route('counter.training.start'))->assertRedirect()->assertSessionHas('counter_training_refused');
        $this->assertFalse(TrainingMode::active());
        CounterBasket::put('pos', $this->centro->id, []);

        Settings::set('counter_training_enabled', false, SettingType::BOOL, $this->centro->id);
        $this->post(route('counter.training.start'))->assertRedirect();
        $this->assertFalse(TrainingMode::active());

        Settings::set('counter_training_enabled', true, SettingType::BOOL, $this->centro->id);
        $this->enter();
        $this->assertSame(1, AuditLog::query()->where('action', 'counter.training.started')->count());
    }

    // --- 5. Leaving happens by itself ------------------------------------------------------------------------------------------------

    public function test_lock_operator_switch_sede_switch_and_the_panel_end_training_and_drop_the_practice_basket(): void
    {
        $manager = User::factory()->create(['pin' => '5678']);
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$this->centro->id, $this->norte->id]);

        foreach ([
            'lock' => fn () => CounterOperator::clear(),
            'operator switch' => fn () => CounterOperator::set($manager),
            // The switch itself is refused while this sede's till is open (a real rule); what matters is its effect.
            'sede switch' => fn () => session(['counter.location_id' => $this->norte->id]),
            'panel' => fn () => $this->get('/'),
        ] as $how => $leave) {
            CounterOperator::set($this->owner);
            session(['counter.location_id' => $this->centro->id]);
            $this->enter();
            CounterBasket::put('pos', $this->centro->id, [['type' => 'genetic', 'id' => $this->genetic->id]]);

            $leave();
            $this->get('/counter/pos');

            $this->assertFalse(TrainingMode::active(), "{$how} did not end training");
            CounterOperator::set($this->owner);
            $this->assertSame([], CounterBasket::get('pos', $this->centro->id), "{$how} kept the practice basket");
        }
        $this->assertSame(4, AuditLog::query()->where('action', 'counter.training.ended')->count());
    }

    // --- 6. The banner ------------------------------------------------------------------------------------------------------------------

    public function test_the_banner_is_on_every_counter_screen_and_only_leaving_removes_it(): void
    {
        $this->enter();

        foreach (['/counter', '/counter/pos', '/counter/bar', '/counter/checkin', '/counter/members', '/counter/till'] as $screen) {
            $html = (string) $this->get($screen)->getContent();
            $this->assertStringContainsString('data-training-banner', $html, "no banner on {$screen}");
            $this->assertStringContainsString(__('MODO FORMACIÓN — nada de esto cuenta'), $html);
            $this->assertStringContainsString('data-training-leave', $html);
        }

        $this->post(route('counter.training.leave'))->assertRedirect();
        $this->assertStringNotContainsString('data-training-banner', (string) $this->get('/counter/pos')->getContent());
    }

    // --- 7. Uploads and the receipt -----------------------------------------------------------------------------------------------------

    public function test_uploads_are_refused_and_the_practice_receipt_is_watermarked_with_no_email(): void
    {
        $this->enter();

        $this->post(route('counter.members.photo', $this->member), ['photo' => UploadedFile::fake()->image('f.jpg'), 'source' => 'counter'], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->post(route('livewire.upload-file'), [], ['X-Livewire' => '1'])->assertForbidden();
        $this->assertNull($this->member->fresh()->photo_path);

        $pos = $this->livewirePost($this->snapshotFrom('/counter/pos', 'counter.dispensary-pos'), ['cashTendered' => '100'],
            [['selectMember', [$this->member->id]], ['chooseGenetic', [$this->genetic->id]], ['addLine', ['1']], ['commitDispensation']])->assertOk();
        $id = $pos->json('components.0.snapshot') ? json_decode((string) $pos->json('components.0.snapshot'), true)['data']['lastDispensationId'] ?? null : null;
        $this->assertNotNull($id, 'the practice commit did not report a dispensation');

        $this->assertStringContainsString('data-training-no-upload', (string) $pos->json('components.0.effects.html'), 'the photo control is still offered');

        $receipt = $this->get(route('counter.pos.receipt', $id))->assertOk();
        $receipt->assertSee(__('COMPROBANTE DE PRÁCTICA — SIN VALIDEZ'));
        $this->assertStringNotContainsString('data-last-sale-email', (string) json_encode($pos->json(), JSON_UNESCAPED_UNICODE));
    }

    // --- The panic button is never practice ---------------------------------------------------------------------------------

    public function test_the_panic_button_ends_training_and_really_locks_down(): void
    {
        $this->enter();

        $this->post(route('counter.panic'))->assertRedirect();

        $this->assertFalse(TrainingMode::active());
        $this->assertSame(1, OrganisationLockdown::query()->withoutGlobalScopes()->count(), 'a panic press in training was rolled back');
        $this->assertSame(1, AuditLog::query()->where('action', 'counter.training.ended')->count());
    }
}
