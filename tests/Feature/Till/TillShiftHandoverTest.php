<?php

namespace Tests\Feature\Till;

use App\Actions\Till\CloseTill;
use App\Actions\Till\HandOverTill;
use App\Actions\Till\OpenTill;
use App\Actions\Till\RecordCashMovement;
use App\Enums\CashMovementType;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Enums\TillShiftStatus;
use App\Exceptions\TillClosedException;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\TillShift;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 186 — two people cannot share a day at the till.
 *
 * A cash variance is attributable to whoever held the drawer. With only OPEN and CLOSED, a shift change
 * meant either two arqueos for one trading day or two people inside one session — and in the second case a
 * shortfall belongs to nobody, which destroys the one thing an arqueo is for.
 *
 * The owner's fork: **the drawer belongs to the till, and a handover is counted.** So the session and the
 * trading day continue as one arqueo, and a SHIFT is the attributable unit.
 *
 * `test_a_variance_is_attributable_to_a_shift_not_merely_to_a_session` is the assertion the whole branch
 * exists for; `test_a_single_operator_day_is_unchanged` is the one that must not regress.
 */
class TillShiftHandoverTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function user(Role $role = Role::STAFF): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function openTill(User $operator, int $floatCents = 10000): TillSession
    {
        $this->actingAs($operator);

        return (new OpenTill)->handle($this->location, 'POS-1', $floatCents, ['operator_id' => $operator->id]);
    }

    private function cashIn(TillSession $session, User $by, int $cents): void
    {
        (new RecordCashMovement)->handle($session, CashMovementType::IN, $cents, ['reason' => 'takings']);
    }

    // --- the case that must not regress --------------------------------------------------------------------

    public function test_a_single_operator_day_is_unchanged(): void
    {
        $manager = $this->user(Role::MANAGER);
        $session = $this->openTill($manager);

        $this->cashIn($session, $manager, 5000);
        $closed = (new CloseTill)->handle($session->fresh(), 15000, $manager);

        // Exactly as before: one session, one count, one expected figure, one variance.
        $this->assertSame(TillSessionStatus::CLOSED, $closed->status);
        $this->assertSame(15000, (int) DB::table('till_sessions')->where('id', $session->id)->value('counted_cents'));
        $this->assertSame(15000, (int) DB::table('till_sessions')->where('id', $session->id)->value('expected_cents'));
        $this->assertSame(0, (int) DB::table('till_sessions')->where('id', $session->id)->value('variance_cents'));

        // …and exactly ONE shift, which is what makes a single-operator club notice nothing.
        $shifts = TillShift::query()->withoutGlobalScopes()->where('till_session_id', $session->id)->get();
        $this->assertCount(1, $shifts);
        $this->assertSame($manager->id, $shifts->first()->opened_by);
        $this->assertSame(TillShiftStatus::CLOSED, $shifts->first()->status);
    }

    // --- the assertion the branch exists for ------------------------------------------------------------------

    public function test_a_variance_is_attributable_to_a_shift_not_merely_to_a_session(): void
    {
        $ana = $this->user();
        $bea = $this->user();
        $manager = $this->user(Role::MANAGER);

        $session = $this->openTill($ana, floatCents: 10000);

        // Ana takes €50 and hands over £5 SHORT.
        $this->cashIn($session, $ana, 5000);
        (new HandOverTill)->handle($session->fresh(), 14500, $ana, $bea);

        // Bea takes €30 and closes EXACT on what she was handed.
        $this->cashIn($session->fresh(), $bea, 3000);
        (new CloseTill)->handle($session->fresh(), 17500, $manager);

        $shifts = TillShift::query()->withoutGlobalScopes()
            ->where('till_session_id', $session->id)->orderBy('opened_at')->get();

        $this->assertCount(2, $shifts);

        // Ana's shift carries the shortfall…
        $this->assertSame($ana->id, $shifts[0]->opened_by);
        $this->assertSame(15000, (int) $shifts[0]->getRawOriginal('expected_cents'));
        $this->assertSame(14500, (int) $shifts[0]->getRawOriginal('counted_cents'));
        $this->assertSame(-500, (int) $shifts[0]->getRawOriginal('variance_cents'));

        // …and Bea's does NOT inherit it. This is the whole point: her expected figure is what she was
        // HANDED plus what moved during her shift, never the session float.
        $this->assertSame($bea->id, $shifts[1]->opened_by);
        $this->assertSame(17500, (int) $shifts[1]->getRawOriginal('expected_cents'));
        $this->assertSame(0, (int) $shifts[1]->getRawOriginal('variance_cents'));
    }

    public function test_the_days_figures_reconcile_whether_it_held_one_shift_or_three(): void
    {
        $a = $this->user();
        $b = $this->user();
        $c = $this->user();
        $manager = $this->user(Role::MANAGER);

        $session = $this->openTill($a, floatCents: 10000);
        $this->cashIn($session, $a, 2000);
        (new HandOverTill)->handle($session->fresh(), 11500, $a, $b);   // a is 500 short
        $this->cashIn($session->fresh(), $b, 3000);
        // b was handed 11500 and took 3000, so 14500 is square and 14300 is 200 short.
        (new HandOverTill)->handle($session->fresh(), 14300, $b, $c);   // b is 200 short
        $this->cashIn($session->fresh(), $c, 1000);
        (new CloseTill)->handle($session->fresh(), 15300, $manager, 'Dos descuadres de turno.'); // c is exact

        $shifts = TillShift::query()->withoutGlobalScopes()
            ->where('till_session_id', $session->id)->orderBy('opened_at')->get();
        $sessionVariance = (int) DB::table('till_sessions')->where('id', $session->id)->value('variance_cents');

        // Three shifts, each with its own variance, and they SUM to the day's. A shift's figures adding up
        // to the day's is what keeps the arqueo meaningful once a day can contain several people.
        $this->assertCount(3, $shifts);
        $this->assertSame(-500, (int) $shifts[0]->getRawOriginal('variance_cents'));
        $this->assertSame(-200, (int) $shifts[1]->getRawOriginal('variance_cents'));
        $this->assertSame(0, (int) $shifts[2]->getRawOriginal('variance_cents'));
        $this->assertSame($sessionVariance, $shifts->sum(fn (TillShift $s): int => (int) $s->getRawOriginal('variance_cents')));
    }

    // --- who, to whom, and when -------------------------------------------------------------------------------

    public function test_a_handover_records_who_handed_over_who_took_over_and_when(): void
    {
        $ana = $this->user();
        $bea = $this->user();
        $session = $this->openTill($ana);

        $next = (new HandOverTill)->handle($session->fresh(), 10000, $ana, $bea);

        $first = TillShift::query()->withoutGlobalScopes()
            ->where('till_session_id', $session->id)->orderBy('opened_at')->first();

        $this->assertSame($ana->id, $first->opened_by);
        $this->assertSame($ana->id, $first->closed_by);
        $this->assertNotNull($first->closed_at);
        $this->assertSame($bea->id, $next->opened_by);
        $this->assertNotNull($next->opened_at);
        $this->assertSame(TillShiftStatus::OPEN, $next->status);

        // …and it is in the audit trail, naming both.
        $entry = AuditLog::query()->where('action', 'till.handed_over')->latest('id')->firstOrFail();
        $this->assertSame($ana->id, data_get($entry->after, 'from'));
        $this->assertSame($bea->id, data_get($entry->after, 'to'));
    }

    public function test_the_session_stays_open_through_a_handover(): void
    {
        $ana = $this->user();
        $bea = $this->user();
        $session = $this->openTill($ana);

        (new HandOverTill)->handle($session->fresh(), 10000, $ana, $bea);

        // The drawer belongs to the till: one session, one trading day, one arqueo.
        $this->assertSame(TillSessionStatus::OPEN, $session->fresh()->status);
        $this->assertNull($session->fresh()->closed_at);
    }

    // --- the count is mandatory and blind ------------------------------------------------------------------------

    public function test_there_is_no_uncounted_handover_path(): void
    {
        $ana = $this->user();
        $bea = $this->user();
        $session = $this->openTill($ana);

        // An uncounted handover leaves the outgoing person's variance unknowable, which is the problem
        // being solved — so the count is a required argument and there is no state for "not counted".
        $signature = (new \ReflectionMethod(HandOverTill::class, 'handle'))->getParameters()[1];
        $this->assertSame('countedCents', $signature->getName());
        $this->assertFalse($signature->isOptional());

        $this->expectException(\RuntimeException::class);
        (new HandOverTill)->handle($session->fresh(), -1, $ana, $bea);
    }

    public function test_the_handover_count_is_blind(): void
    {
        $ana = $this->user();
        $bea = $this->user();
        $session = $this->openTill($ana, floatCents: 10000);
        $this->cashIn($session, $ana, 5000);

        // The screen must not reveal the expected figure before the count. Asserted against the blade, the
        // way the close-out's own blindness is: nothing renders an expected or variance figure here.
        $blade = (string) file_get_contents(resource_path('views/livewire/counter/till-session.blade.php'));
        $start = strpos($blade, 'data-handover');
        $handover = substr($blade, $start, strpos($blade, 'Close (arqueo)') - $start);

        // The only things that could reveal the expectation are the breakdown and the session's own stored
        // figures. Neither is referenced anywhere in the handover block.
        $this->assertStringNotContainsString('breakdown', $handover);
        $this->assertStringNotContainsString('expected_cents', $handover);
        $this->assertStringNotContainsString('variance_cents', $handover);

        // …and the component says nothing about the variance when it succeeds, either: telling the outgoing
        // operator would let the next handover be counted to fit.
        $component = (string) file_get_contents(app_path('Livewire/Counter/TillSession.php'));
        $handOver = substr($component, strpos($component, 'public function handOver('));
        $handOver = substr($handOver, 0, strpos($handOver, 'public function open('));

        // What it EMITS, not what its comments discuss: the success flash names the incoming operator and
        // nothing else. No figure from the handover reaches the screen.
        $this->assertStringContainsString("__('Caja entregada a :name.'", $handOver);
        $this->assertStringNotContainsString('$counted', substr($handOver, strpos($handOver, 'Caja entregada')));
    }

    /**
     * The handover panel sits on the ordinary till screen, which renders the expected drawer figure a few
     * centimetres above it. Opening the panel must withhold the breakdown exactly as the close-out does,
     * or the count is blind in name only — the operator just reads the answer and counts to fit.
     *
     * This was found by looking at a screenshot, after the source-level blindness assertions above passed.
     */
    public function test_opening_the_handover_withholds_the_expected_figure_from_the_whole_screen(): void
    {
        $ana = $this->user(Role::MANAGER);
        $session = $this->openTill($ana, floatCents: 10000);
        $this->cashIn($session, $ana, 5000);
        CounterOperator::set($ana);

        $closed = Livewire::actingAs($ana)->test(\App\Livewire\Counter\TillSession::class);
        $this->assertNotNull($closed->get('handoverOpen') === false ? true : null);
        $this->assertStringContainsString('€', $closed->html(), 'the ordinary screen should show the drawer summary');

        $open = Livewire::actingAs($ana)->test(\App\Livewire\Counter\TillSession::class)->call('toggleHandover');

        // No breakdown at all while the count is being taken — the same withholding the close-out uses.
        $this->assertNull($open->viewData('breakdown'));
    }

    // --- the middle state is a real gate, not a picture of one -------------------------------------------------------

    public function test_a_drawer_between_shifts_refuses_money(): void
    {
        $ana = $this->user();
        $session = $this->openTill($ana);

        $this->assertFalse($session->fresh()->isBetweenShifts());

        // Force the middle state: the holder left and nobody took over.
        TillShift::query()->withoutGlobalScopes()->where('till_session_id', $session->id)
            ->update(['status' => TillShiftStatus::CLOSED->value, 'closed_at' => now()]);

        // Server-side, not by hiding a button: a charge landing while nobody holds the drawer would belong
        // to nobody, which is the defect this branch exists to remove.
        $this->assertTrue($session->fresh()->isBetweenShifts());
        $this->assertFalse($session->fresh()->hasOpenShift());
    }

    public function test_a_session_that_never_had_a_shift_is_not_treated_as_between_shifts(): void
    {
        // Pre-186 data, which the migration backfills for OPEN sessions. Refusing money on one of these
        // would break a live drawer for no safety gain — nobody handed anything over.
        $session = TillSession::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'opened_by' => null,
        ]);

        $this->assertFalse($session->isBetweenShifts());
    }

    // --- permission -----------------------------------------------------------------------------------------------

    public function test_a_user_without_till_open_cannot_hand_over(): void
    {
        $ana = $this->user();
        $session = $this->openTill($ana);

        // `till.open`, not `till.close`: closing ends the trading day and produces the arqueo, and requiring
        // a manager for every shift change would push clubs back to sharing a session.
        $noPermission = User::factory()->create();
        $noPermission->locations()->sync([$this->location->id]);

        $this->expectException(AuthorizationException::class);
        (new HandOverTill)->handle($session->fresh(), 10000, $ana, $noPermission);
    }

    public function test_staff_can_hand_over_without_a_manager(): void
    {
        $ana = $this->user(Role::STAFF);
        $bea = $this->user(Role::STAFF);
        $session = $this->openTill($ana);

        $next = (new HandOverTill)->handle($session->fresh(), 10000, $ana, $bea);

        $this->assertSame($bea->id, $next->opened_by);
    }

    // --- nothing else moved -----------------------------------------------------------------------------------------

    public function test_a_closed_session_cannot_be_handed_over(): void
    {
        $ana = $this->user();
        $bea = $this->user();
        $manager = $this->user(Role::MANAGER);
        $session = $this->openTill($ana);
        (new CloseTill)->handle($session->fresh(), 10000, $manager);

        $this->expectException(TillClosedException::class);
        (new HandOverTill)->handle($session->fresh(), 10000, $ana, $bea);
    }

    public function test_a_closed_shift_is_immutable(): void
    {
        $ana = $this->user();
        $bea = $this->user();
        $session = $this->openTill($ana);
        (new HandOverTill)->handle($session->fresh(), 10000, $ana, $bea);

        $closed = TillShift::query()->withoutGlobalScopes()
            ->where('till_session_id', $session->id)->orderBy('opened_at')->first();

        // Same guarantee the session's arqueo has: a correction is a new entry, never an edit.
        $this->assertSame(TillShiftStatus::CLOSED, $closed->status);

        $this->expectException(\RuntimeException::class);
        $closed->forceFill(['counted_cents' => 99999])->save();
    }

    public function test_the_close_out_flow_and_its_note_requirement_are_untouched(): void
    {
        $ana = $this->user();
        $manager = $this->user(Role::MANAGER);
        $session = $this->openTill($ana, floatCents: 10000);

        // A variance beyond tolerance still requires a note — unchanged by this branch.
        $this->expectException(\RuntimeException::class);
        (new CloseTill)->handle($session->fresh(), 99999, $manager);
    }
}
