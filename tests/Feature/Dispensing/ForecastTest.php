<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Members\UpdateDeclaredForecast;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\ConsumptionForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorised_volume_sums_only_active_members_declared_forecasts(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        Member::factory()->create(['organisation_id' => $org->id, 'status' => MemberStatus::ACTIVE, 'declared_monthly_cg' => 5000]);
        Member::factory()->create(['organisation_id' => $org->id, 'status' => MemberStatus::ACTIVE, 'declared_monthly_cg' => 3000]);
        Member::factory()->create(['organisation_id' => $org->id, 'status' => MemberStatus::EXPELLED, 'declared_monthly_cg' => 9000]); // excluded

        $this->assertSame(8000, ConsumptionForecast::authorisedVolumeCg($org->id));
    }

    public function test_updating_the_forecast_is_audited(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $member = Member::factory()->create(['organisation_id' => $org->id, 'declared_monthly_cg' => 3000]);

        (new UpdateDeclaredForecast)->handle($member, 6000);

        $this->assertSame(6000, $member->fresh()->declared_monthly_cg);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.forecast.updated']);
    }
}
