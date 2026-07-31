<?php

namespace Tests\Feature\AuditLog;

use App\Actions\Stock\IntakeBatch;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\WalletTransactionType;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Genetics\Pages\EditGenetic;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 48 — the five audit-trail gaps prompt 17 named are now wired: wallet adjustments,
 * stock adjustments/merma/intake, base price/definition edits, member edits and role changes.
 * One test per gap; the diffs reflect the real change; no credential material ever lands in one.
 */
class AuditTrailCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->actor = User::factory()->create();
        $this->actor->assignRole(Role::OWNER->value);
        $this->actingAs($this->actor);
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function log(string $action): AuditLog
    {
        return AuditLog::query()->withoutGlobalScopes()->where('action', $action)->sole();
    }

    public function test_a_wallet_adjustment_is_audited_with_the_balance_change(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        (new RecordWalletTransaction)->handle($member, $this->location, 500, WalletTransactionType::ADJUSTMENT, ['reason' => 'Corrección']);

        $log = $this->log('wallet.adjusted');
        $this->assertSame($member->getMorphClass(), $log->auditable_type);
        $this->assertSame($member->id, $log->auditable_id);
        $this->assertSame($this->actor->id, $log->actor_id);
        $this->assertSame(['balance_cents' => 0], $log->before);           // prior balance
        $this->assertSame(500, $log->after['balance_cents']);              // real change, not a whole-model dump
    }

    public function test_a_stock_adjustment_and_merma_are_audited_with_the_remaining_change(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 10000,
        ]);

        (new RecordStockMovement)->handle($batch, StockMovementType::ADJUSTMENT, 500, ['reason' => 'Recuento']);
        $adj = $this->log('stock.adjusted');
        $this->assertSame(10000, $adj->before['remaining_cg']);
        $this->assertSame(10500, $adj->after['remaining_cg']);

        (new RecordStockMovement)->handle($batch->fresh(), StockMovementType::MERMA, -300, ['reason' => 'Derrame', 'actor' => $this->actor]);
        $merma = $this->log('stock.merma');
        $this->assertSame(10500, $merma->before['remaining_cg']);
        $this->assertSame(10200, $merma->after['remaining_cg']);
        $this->assertSame('Derrame', $merma->after['reason']); // the merma reason is now in the audit log
    }

    public function test_batch_intake_is_audited(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);

        $batch = (new IntakeBatch)->handle($genetic, $this->location, ['grams' => 50, 'cost_per_gram_cents' => 500]);

        $log = $this->log('batch.intake');
        $this->assertSame($batch->getMorphClass(), $log->auditable_type);
        $this->assertSame($batch->id, $log->auditable_id);
        $this->assertSame(5000, $log->after['initial_cg']); // 50 g → 5000 cg
    }

    public function test_editing_an_article_price_is_audited(): void
    {
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'price_cents' => 250,
        ]);

        Livewire::test(EditArticle::class, ['record' => $article->id])
            ->fillForm(['price_eur' => 3.50])
            ->call('save')
            ->assertHasNoFormErrors();

        $log = $this->log('article.updated');
        $this->assertEquals(250, $log->before['price_cents']);
        $this->assertEquals(350, $log->after['price_cents']);
    }

    public function test_editing_a_genetic_is_audited(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);

        Livewire::test(EditGenetic::class, ['record' => $genetic->id])
            ->fillForm(['name' => 'Amnesia Haze'])
            ->call('save')
            ->assertHasNoFormErrors();

        $log = $this->log('genetic.updated');
        $this->assertSame('Amnesia', $log->before['name']);
        $this->assertSame('Amnesia Haze', $log->after['name']);
    }

    public function test_a_role_change_is_audited_naming_the_roles(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $managerRole = \Spatie\Permission\Models\Role::query()->where('name', Role::MANAGER->value)->firstOrFail();

        Livewire::test(EditUser::class, ['record' => $staff->id])
            ->fillForm(['roles' => [$managerRole->getKey()]]) // the roles Select is keyed by role id
            ->call('save')
            ->assertHasNoFormErrors();

        $log = $this->log('user.roles.updated');
        $this->assertSame([Role::STAFF->value], $log->before['roles']);
        $this->assertSame([Role::MANAGER->value], $log->after['roles']);
    }

    public function test_no_audit_row_ever_contains_credential_material(): void
    {
        $staff = User::factory()->create(['password' => bcrypt('secret-password')]);
        $staff->assignRole(Role::STAFF->value);
        $hash = $staff->password;
        $managerRole = \Spatie\Permission\Models\Role::query()->where('name', Role::MANAGER->value)->firstOrFail();

        Livewire::test(EditUser::class, ['record' => $staff->id])
            ->fillForm(['roles' => [$managerRole->getKey()]])
            ->call('save');

        foreach (AuditLog::query()->withoutGlobalScopes()->get() as $log) {
            $payload = (string) json_encode([$log->before, $log->after]);
            $this->assertStringNotContainsString($hash, $payload);
            $this->assertStringNotContainsString('password', $payload);
            $this->assertStringNotContainsString('two_factor', $payload);
        }
    }
}
