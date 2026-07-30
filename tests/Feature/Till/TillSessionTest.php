<?php

namespace Tests\Feature\Till;

use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Actions\Till\RecordCashMovement;
use App\Enums\CashMovementType;
use App\Enums\DispensationStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Exceptions\TillAlreadyOpenException;
use App\Exceptions\TillClosedException;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\TillSummary;
use App\Support\ZReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TillSessionTest extends TestCase
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

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value); // has till.close

        return $user;
    }

    public function test_expected_cash_is_derived_and_excludes_wallet(): void
    {
        $session = (new OpenTill)->handle($this->location, 'POS-1', 20000); // €200 float
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'till_session_id' => $session->id, 'status' => DispensationStatus::COMPLETED,
            'total_cents' => 19000, 'cash_cents' => 15000, 'wallet_cents' => 4000, // €150 cash, €40 wallet
        ]);
        Order::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'till_session_id' => $session->id,
            'status' => OrderStatus::COMPLETED, 'total_cents' => 3000, 'cash_cents' => 3000, 'wallet_cents' => 0, // €30 bar cash
        ]);
        (new RecordCashMovement)->handle($session, CashMovementType::PETTY_CASH, 2500); // −€25
        (new RecordCashMovement)->handle($session, CashMovementType::BANKED, 10000);    // −€100

        // 200 + 150 + 30 − 25 − 100 = €255, excluding the €40 wallet.
        $this->assertSame(25500, TillSummary::expectedCents($session));
    }

    public function test_two_sessions_cannot_open_on_one_terminal(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $this->expectException(TillAlreadyOpenException::class);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }

    public function test_blind_close_reveals_variance_and_requires_a_note_beyond_tolerance(): void
    {
        // Within tolerance (default €5) → no note required.
        $ok = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $closed = (new CloseTill)->handle($ok, 10000, $this->manager());
        $this->assertSame(0, $closed->variance_cents->cents);
        $this->assertSame(TillSessionStatus::CLOSED, $closed->status);

        // Beyond tolerance without a note → refused.
        $over = (new OpenTill)->handle($this->location, 'POS-2', 10000);
        try {
            (new CloseTill)->handle($over, 12000, $this->manager()); // €20 over
            $this->fail('A note should be required.');
        } catch (RuntimeException) {
            // expected
        }
        // With a note → accepted.
        $closedOver = (new CloseTill)->handle($over, 12000, $this->manager(), 'Miscount corrected next shift');
        $this->assertSame(2000, $closedOver->variance_cents->cents);
    }

    public function test_a_closed_session_rejects_new_movements_and_commits(): void
    {
        $session = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        (new CloseTill)->handle($session, 10000, $this->manager());

        $this->expectException(TillClosedException::class);
        (new RecordCashMovement)->handle($session->fresh(), CashMovementType::IN, 500);
    }

    public function test_a_void_adjusts_the_expected_figure(): void
    {
        $session = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'till_session_id' => $session->id, 'status' => DispensationStatus::COMPLETED,
            'total_cents' => 5000, 'cash_cents' => 5000, 'wallet_cents' => 0,
        ]);

        $this->assertSame(15000, TillSummary::expectedCents($session)); // 100 float + 50 cash

        $dispensation->update(['status' => DispensationStatus::VOIDED]);
        $this->assertSame(10000, TillSummary::expectedCents($session)); // void releases the €50
    }

    public function test_z_report_totals_match_the_session(): void
    {
        $session = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'till_session_id' => $session->id, 'status' => DispensationStatus::COMPLETED,
            'total_cents' => 4000, 'cash_cents' => 4000, 'wallet_cents' => 0,
        ]);
        $closed = (new CloseTill)->handle($session, 14000, $this->manager());

        $report = ZReport::for($closed);
        $this->assertSame(14000, $report['expected']);
        $this->assertSame(14000, $report['counted']);
        $this->assertSame(0, $report['variance']);
        $this->assertSame(1, $report['transaction_count']);
    }
}
