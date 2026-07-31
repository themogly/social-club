<?php

namespace Tests\Feature\Members;

use App\Actions\Bar\CommitOrder;
use App\Actions\Members\AnonymiseMember;
use App\Actions\Members\ExportMemberData;
use App\Actions\Till\OpenTill;
use App\Enums\CheckInMethod;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Article;
use App\Models\CheckIn;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prompt 52 — the member's history, the admin RGPD data pack (Art. 20 portability) and the member's own
 * PWA export all omitted bar orders and check-in visits. This proves all three now include them, and —
 * the important finding — that erasure (Art. 17) does NOT have the same gap: orders/visits carry no
 * standalone PII, so scrubbing the member row covers them; they are kept attributed, like the ledger.
 */
class MemberDataCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'email' => 'socio@example.es', 'status' => MemberStatus::ACTIVE,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE,
        ]);

        // A bar order through the REAL writer + a visit — the two record types prompt 52 surfaces.
        $operator = User::factory()->create();
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'price_cents' => 250, 'stock' => 10, 'active' => true,
        ]);
        $till = (new OpenTill)->handle($this->location, 'POS-1', 10000);
        (new CommitOrder)->handle($this->location, [['article_id' => $article->id, 'qty' => 2]], [
            'member_id' => $this->member->id, 'operator_id' => $operator->id,
            'till_session_id' => $till->id, 'idempotency_key' => (string) Str::ulid(),
        ]);

        CheckIn::create([
            'organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->location->id,
            'checked_in_at' => now()->subHour(), 'checked_out_at' => now(), 'operator_id' => $operator->id,
            'method' => CheckInMethod::QR,
        ]);
    }

    public function test_the_rgpd_data_pack_includes_bar_orders_and_visits(): void
    {
        $pack = (new ExportMemberData)->handle($this->member);

        $this->assertArrayHasKey('orders', $pack);
        $this->assertArrayHasKey('visits', $pack);
        $this->assertCount(1, $pack['orders']);
        $this->assertSame(500, $pack['orders'][0]['total_cents']); // 2 × €2,50
        $this->assertCount(1, $pack['visits']);
    }

    public function test_erasure_does_not_skip_orders_or_visits_and_leaves_no_pii(): void
    {
        (new AnonymiseMember)->handle($this->member);
        $this->member->refresh();

        $this->assertNull($this->member->email);                 // member row scrubbed…
        $this->assertSame('ANONIMIZADO', $this->member->first_name);

        // …and the order + visit are KEPT, attributed to the anonymised record (books stay whole); they
        // hold no standalone PII, so there is no per-record erasure gap to close.
        $this->assertSame(1, $this->member->orders()->withoutGlobalScopes()->count());
        $this->assertSame(1, $this->member->checkIns()->withoutGlobalScopes()->count());
    }

    public function test_the_member_pwa_export_includes_orders_and_visits(): void
    {
        $response = $this->actingAs($this->member, 'member')->get(route('socio.export'));
        $response->assertOk();
        $data = $response->json();

        $this->assertArrayHasKey('pedidos', $data);
        $this->assertArrayHasKey('visitas', $data);
        $this->assertCount(1, $data['pedidos']);
        $this->assertCount(1, $data['visitas']);
    }

    public function test_the_member_pwa_history_shows_the_bar_and_visits_sections(): void
    {
        $response = $this->actingAs($this->member, 'member')->get(route('socio.history'));
        $response->assertOk();
        $response->assertSee(__('Barra'));
        $response->assertSee(__('Visitas'));
    }
}
