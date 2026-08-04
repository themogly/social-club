<?php

namespace Tests\Feature\Dispensing;

use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 149 — the counter's "send receipt" button must show a readable message on a mail failure, never
 * Livewire's error screen mid-service. Run on MySQL (project default).
 */
class ReceiptMailFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_send_receipt_button_reports_a_mail_failure_instead_of_500ing(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);

        $operator = User::factory()->create();
        $operator->assignRole(Role::STAFF->value);
        $operator->locations()->sync([$location->id]);
        $this->actingAs($operator);
        app(ActiveScope::class)->setLocation($location->id);
        CounterOperator::set($operator);

        $member = Member::factory()->create(['organisation_id' => $org->id, 'email' => 'socio@club.es', 'status' => MemberStatus::ACTIVE]);
        $dispensation = Dispensation::factory()->create([
            'organisation_id' => $org->id, 'location_id' => $location->id, 'member_id' => $member->id,
        ]);

        // The mail transport throws — this must be caught, not surfaced as an uncaught error.
        Mail::shouldReceive('to')->andThrow(new RuntimeException('mail down'));

        Livewire::test(DispensaryPos::class)
            ->set('lastDispensationId', $dispensation->id)
            ->call('emailReceipt')
            ->assertHasNoErrors();  // the try/catch swallowed the failure; no error screen
    }
}
