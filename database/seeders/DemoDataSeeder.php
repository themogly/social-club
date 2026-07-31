<?php

namespace Database\Seeders;

use App\Actions\Bar\CommitOrder;
use App\Enums\BatchStatus;
use App\Enums\CategoryAppliesTo;
use App\Enums\CheckInMethod;
use App\Enums\CultivationType;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Enums\DispensationStatus;
use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Enums\IdDocumentType;
use App\Enums\MembershipPeriod;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\SettingType;
use App\Enums\StockMovementType;
use App\Enums\StrainType;
use App\Enums\TillSessionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Category;
use App\Models\CheckIn;
use App\Models\Discount;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\TillSession;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\Support\Weight;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Local-only demo data: one org, two premises, tiers, the seeded staff, ~20
 * members across every status, genetics with per-location prices + batches
 * (opening stock as INTAKE movements), articles, discounts, and a fortnight of
 * dispensations, orders, check-ins, expenses and closed till sessions — so the
 * dashboard (prompt 14/15) has real data from day one. Opening balances always
 * enter through movements/adjustments, never free-typed (the go-live path).
 */
class DemoDataSeeder extends Seeder
{
    private ActiveScope $scope;

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $this->scope = app(ActiveScope::class);

        $org = Organisation::create([
            'name' => 'CSC platform',
            'legal_name' => 'TBD-LEGAL-NAME',      // OVERNIGHT-PLACEHOLDER — CONFIRM
            'tax_id' => 'TBD-CIF-NIF',             // OVERNIGHT-PLACEHOLDER — CONFIRM
            'address' => 'TBD-ADDRESS',            // OVERNIGHT-PLACEHOLDER — CONFIRM
            'contact_email' => 'info@club.test',
        ]);
        $this->scope->setOrganisation($org->id);

        $this->seedSettings($org->id);

        $centro = Location::create([
            'organisation_id' => $org->id, 'name' => 'Sede Centro', 'address' => 'TBD-ADDRESS',
            'capacity' => 50, 'timezone' => 'Europe/Madrid', 'business_day_cutoff' => '06:00',
            'opening_time' => '12:00', 'closing_time' => '00:00', 'accent' => '#2563eb', 'active' => true,
        ]);
        $norte = Location::create([
            'organisation_id' => $org->id, 'name' => 'Sede Norte', 'address' => 'TBD-ADDRESS',
            'capacity' => 40, 'timezone' => 'Europe/Madrid', 'business_day_cutoff' => '06:00',
            'opening_time' => '17:00', 'closing_time' => '02:00', 'accent' => '#16a34a', 'active' => true,
        ]);
        $locations = [$centro, $norte];

        $staff = $this->attachStaff($locations);

        $tierSocio = MembershipTier::create([
            'organisation_id' => $org->id, 'name' => 'Socio', 'default_fee_cents' => 2000,
            'default_period' => MembershipPeriod::YEARLY, 'active' => true,
        ]);
        $tierTera = MembershipTier::create([
            'organisation_id' => $org->id, 'name' => 'Terapéutico', 'default_fee_cents' => 1000,
            'default_period' => MembershipPeriod::YEARLY, 'active' => true,
        ]);

        // Genetic categories are a house GRADING (prompt 66) — orthogonal to strain type (sativa/indica/
        // hybrid) and product type, so the three POS filter rows select genuinely different sets instead
        // of one redundant "Flores" on every flower (which is what made them read as duplicates).
        $geneticCategories = [
            'Premium' => Category::create(['organisation_id' => $org->id, 'name' => 'Premium', 'applies_to' => CategoryAppliesTo::GENETIC]),
            'Estándar' => Category::create(['organisation_id' => $org->id, 'name' => 'Estándar', 'applies_to' => CategoryAppliesTo::GENETIC]),
        ];
        $catBar = Category::create(['organisation_id' => $org->id, 'name' => 'Bar', 'applies_to' => CategoryAppliesTo::ARTICLE]);

        ExpenseCategorySeeder::seedFor($org->id);
        // Consumibles is the petty-cash (TILL) category — the drawer buys bags, gloves, etc.
        $pettyCashCat = ExpenseCategory::where('name', 'Consumibles')->first();

        Discount::create(['organisation_id' => $org->id, 'name' => 'Personal', 'kind' => DiscountKind::STAFF, 'mode' => DiscountMode::PERCENT, 'value_bp' => 1000, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true]);
        Discount::create(['organisation_id' => $org->id, 'name' => 'Terapéutico', 'kind' => DiscountKind::THERAPEUTIC, 'mode' => DiscountMode::PERCENT, 'value_bp' => 1500, 'applies_to' => DiscountAppliesTo::GENETIC, 'active' => true]);

        [$batchesByLocation, $priceByBatch] = $this->seedCatalogue($org->id, $locations, $geneticCategories, $catBar);

        $membersByLocation = $this->seedMembers($org->id, $locations, $tierSocio, $tierTera);

        $this->seedFortnight($org->id, $locations, $staff, $batchesByLocation, $priceByBatch, $membersByLocation, $pettyCashCat);
    }

    private function seedSettings(string $orgId): void
    {
        foreach (Settings::DEFAULTS as $key => $value) {
            $type = match (true) {
                is_bool($value) => SettingType::BOOL,
                is_int($value) => SettingType::INT,
                is_array($value) => SettingType::JSON,
                default => SettingType::STRING,
            };

            Setting::create([
                'organisation_id' => $orgId,
                'location_id' => null,
                'key' => $key,
                'value' => is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value),
                'type' => $type,
            ]);
        }
    }

    /**
     * @param  array<int, Location>  $locations
     * @return array{owner: User, manager: User, staff: User}
     */
    private function attachStaff(array $locations): array
    {
        $ids = collect($locations)->pluck('id')->all();
        $owner = User::where('email', 'owner@club.test')->firstOrFail();
        $manager = User::where('email', 'manager@club.test')->firstOrFail();
        $staff = User::where('email', 'staff@club.test')->firstOrFail();

        $owner->locations()->sync($ids);
        $manager->locations()->sync($ids);
        $staff->locations()->sync([$locations[0]->id]);

        return ['owner' => $owner, 'manager' => $manager, 'staff' => $staff];
    }

    /**
     * @param  array<int, Location>  $locations
     * @return array{0: array<string, array<int, Batch>>, 1: array<string, int>}
     */
    /**
     * @param  array<string, Category>  $geneticCategories
     */
    private function seedCatalogue(string $orgId, array $locations, array $geneticCategories, Category $catBar): array
    {
        // [name, thc_bp, cbd_bp, cultivation, strain type, grade category] — strain + grade are spread
        // ORTHOGONALLY (prompt 66): sativa/indica/hybrid across both Premium and Estándar, and a CBD
        // variety with NO strain type. So Variedad, Categoría and Tipo select different sets.
        $definitions = [
            ['Amnesia Haze', 2200, 50, CultivationType::INDOOR, StrainType::SATIVA, 'Premium'],
            ['Critical Kush', 1900, 80, CultivationType::INDOOR, StrainType::INDICA, 'Estándar'],
            ['CBD Charlotte', 600, 1200, CultivationType::GREENHOUSE, null, 'Estándar'],
            ['Moby Dick', 2300, 40, CultivationType::OUTDOOR, StrainType::SATIVA, 'Premium'],
            ['Northern Lights', 1800, 60, CultivationType::INDOOR, StrainType::INDICA, 'Estándar'],
            ['Purple Haze', 2000, 30, CultivationType::OUTDOOR, StrainType::HYBRID, 'Premium'],
        ];

        $batchesByLocation = [];
        $priceByBatch = [];

        foreach ($definitions as [$name, $thc, $cbd, $cultivation, $strain, $grade]) {
            $genetic = Genetic::create([
                'organisation_id' => $orgId, 'name' => $name, 'category_id' => $geneticCategories[$grade]->id,
                'thc_bp' => $thc, 'cbd_bp' => $cbd, 'cultivation_type' => $cultivation, 'strain_type' => $strain,
                'terpenes' => ['mirceno', 'limoneno'], 'published' => true, 'active' => true,
            ]);

            foreach ($locations as $location) {
                $this->scope->setLocation($location->id);
                $pricePerGram = random_int(700, 1200);

                GeneticPrice::create([
                    'organisation_id' => $orgId, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                    'tier_id' => null, 'price_per_gram_cents' => $pricePerGram, 'low_stock_threshold_cg' => 5000, 'active' => true,
                ]);

                $initial = random_int(20000, 80000); // cg
                $batch = Batch::create([
                    'organisation_id' => $orgId, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                    'batch_no' => 'B-'.strtoupper(Str::random(6)), 'acquired_or_harvested_on' => now()->subDays(30),
                    'initial_cg' => $initial, 'remaining_cg' => $initial, 'cost_per_gram_cents' => random_int(300, 600),
                    'status' => BatchStatus::OPEN,
                ]);

                StockMovement::create([
                    'organisation_id' => $orgId, 'location_id' => $location->id,
                    'stockable_type' => Batch::class, 'stockable_id' => $batch->id,
                    'qty_cg' => $initial, 'type' => StockMovementType::INTAKE,
                    'reason' => 'Existencias iniciales', 'reference' => $batch->batch_no,
                ]);

                $batchesByLocation[$location->id][] = $batch;
                $priceByBatch[$batch->id] = $pricePerGram;
            }
        }

        foreach ($locations as $location) {
            $this->scope->setLocation($location->id);
            foreach (['Agua' => 150, 'Refresco' => 200, 'Café' => 120, 'Mechero' => 100, 'Papel de liar' => 90] as $name => $cents) {
                Article::create([
                    'organisation_id' => $orgId, 'location_id' => $location->id, 'name' => $name,
                    'category_id' => $catBar->id, 'price_cents' => $cents, 'stock' => random_int(20, 100),
                    'low_stock_threshold' => 10, 'active' => true,
                ]);
            }
        }

        return [$batchesByLocation, $priceByBatch];
    }

    /**
     * @param  array<int, Location>  $locations
     * @return array<string, array<int, array{member: Member, balance: int}>>
     */
    private function seedMembers(string $orgId, array $locations, MembershipTier $tierSocio, MembershipTier $tierTera): array
    {
        $membersByLocation = [];
        $number = 1;

        // 16 active members split across the two locations, plus one of each other status.
        for ($i = 0; $i < 16; $i++) {
            $location = $locations[$i % count($locations)];
            $member = $this->makeMember($orgId, $number++, MemberStatus::ACTIVE, $i < 3);

            $this->scope->setLocation($location->id);
            Membership::create([
                'organisation_id' => $orgId, 'member_id' => $member->id, 'location_id' => $location->id,
                'tier_id' => $member->is_therapeutic ? $tierTera->id : $tierSocio->id,
                'starts_at' => now()->subMonths(6), 'expires_at' => now()->addMonths(6),
                'fee_cents' => $member->is_therapeutic ? 1000 : 2000, 'status' => MembershipStatus::ACTIVE,
            ]);

            $balance = random_int(0, 6000);
            if ($balance > 0) {
                WalletTransaction::create([
                    'organisation_id' => $orgId, 'member_id' => $member->id, 'location_id' => $location->id,
                    'amount_cents' => $balance, 'type' => WalletTransactionType::ADJUSTMENT,
                    'balance_after_cents' => $balance, 'reason' => 'Saldo inicial (importación go-live)',
                ]);
            }

            $membersByLocation[$location->id][] = ['member' => $member, 'balance' => $balance];
        }

        foreach ([MemberStatus::APPLICANT, MemberStatus::APPLICANT, MemberStatus::INACTIVE, MemberStatus::EXPIRED, MemberStatus::SUSPENDED, MemberStatus::EXPELLED] as $status) {
            $this->makeMember($orgId, $number++, $status, false);
        }

        return $membersByLocation;
    }

    private function makeMember(string $orgId, int $number, MemberStatus $status, bool $therapeutic): Member
    {
        return Member::create([
            'organisation_id' => $orgId,
            'member_no' => sprintf('M-%05d', $number),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('6########'),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-21 years'),
            'address' => fake()->streetAddress(),
            'document_type' => IdDocumentType::DNI,
            'document_number' => fake()->numerify('########').'Z',
            'status' => $status,
            'is_therapeutic' => $therapeutic,
            'joined_at' => $status === MemberStatus::APPLICANT ? null : now()->subMonths(random_int(1, 18)),
            'carencia_ends_at' => now()->subDays(random_int(1, 30)),
            'declared_monthly_cg' => 5000,
        ]);
    }

    /**
     * @param  array<int, Location>  $locations
     * @param  array{owner: User, manager: User, staff: User}  $staff
     * @param  array<string, array<int, Batch>>  $batchesByLocation
     * @param  array<string, int>  $priceByBatch
     * @param  array<string, array<int, array{member: Member, balance: int}>>  $membersByLocation
     */
    private function seedFortnight(string $orgId, array $locations, array $staff, array $batchesByLocation, array $priceByBatch, array $membersByLocation, ?ExpenseCategory $pettyCashCat): void
    {
        for ($daysAgo = 13; $daysAgo >= 0; $daysAgo--) {
            $day = now()->subDays($daysAgo)->startOfDay();

            foreach ($locations as $location) {
                $this->scope->setLocation($location->id);

                $members = $membersByLocation[$location->id] ?? [];
                $batches = $batchesByLocation[$location->id] ?? [];
                if ($members === [] || $batches === []) {
                    continue;
                }

                $till = TillSession::create([
                    'organisation_id' => $orgId, 'location_id' => $location->id, 'terminal' => 'POS-1',
                    'opened_by' => $staff['owner']->id, 'opened_at' => $day->copy()->setTime(12, 0),
                    'float_cents' => 10000, 'status' => TillSessionStatus::OPEN,
                ]);
                $expectedCash = 10000;

                $expectedCash += $this->seedDispensations($orgId, $location, $till, $day, $staff['staff'], $batches, $priceByBatch, $membersByLocation[$location->id]);
                $expectedCash += $this->seedOrders($orgId, $location, $till, $day, $staff['staff']);

                if ($pettyCashCat !== null && random_int(0, 2) === 0) {
                    $amount = random_int(500, 3000);
                    Expense::create([
                        'organisation_id' => $orgId, 'location_id' => $location->id, 'category_id' => $pettyCashCat->id,
                        'amount_cents' => $amount, 'paid_from' => ExpensePaidFrom::TILL_CASH, 'kind' => ExpenseKind::TILL,
                        'till_session_id' => $till->id, 'recorded_by' => $staff['manager']->id, 'incurred_on' => $day,
                    ]);
                    $expectedCash -= $amount;
                }

                $variance = random_int(-200, 200);
                $till->update([
                    'closed_by' => $staff['owner']->id, 'closed_at' => $day->copy()->setTime(23, 0),
                    'counted_cents' => $expectedCash + $variance, 'expected_cents' => $expectedCash,
                    'variance_cents' => $variance, 'status' => TillSessionStatus::CLOSED,
                ]);
            }
        }
    }

    /**
     * @param  array<int, Batch>  $batches
     * @param  array<string, int>  $priceByBatch
     * @param  array<int, array{member: Member, balance: int}>  $members
     */
    private function seedDispensations(string $orgId, Location $location, TillSession $till, Carbon $day, User $operator, array $batches, array $priceByBatch, array &$members): int
    {
        $cashTaken = 0;

        for ($i = 0; $i < random_int(2, 4); $i++) {
            $index = array_rand($members);
            $member = $members[$index]['member'];
            $batch = $batches[array_rand($batches)];
            if ($batch->remaining_cg->centigrams < 500) {
                continue;
            }

            $time = $day->copy()->setTime(random_int(13, 22), random_int(0, 59));
            CheckIn::create([
                'organisation_id' => $orgId, 'member_id' => $member->id, 'location_id' => $location->id,
                'checked_in_at' => $time, 'checked_out_at' => $time->copy()->addHour(),
                'operator_id' => $operator->id, 'method' => CheckInMethod::QR,
            ]);

            $pricePerGram = $priceByBatch[$batch->id];
            $gramsCg = random_int(50, 300);
            $lineTotal = (int) round_half_up($pricePerGram * $gramsCg / 100);

            $balance = $members[$index]['balance'];
            if ($balance >= $lineTotal && random_int(0, 1) === 1) {
                $wallet = $lineTotal;
                $cash = 0;
                $members[$index]['balance'] = $balance - $lineTotal;
            } else {
                $wallet = 0;
                $cash = $lineTotal;
            }

            $dispensation = Dispensation::create([
                'organisation_id' => $orgId, 'member_id' => $member->id, 'location_id' => $location->id,
                'operator_id' => $operator->id, 'till_session_id' => $till->id,
                'total_cents' => $lineTotal, 'cash_cents' => $cash, 'wallet_cents' => $wallet,
                'status' => DispensationStatus::COMPLETED, 'idempotency_key' => (string) Str::ulid(),
                'dispensed_at' => $time,
            ]);

            DispensationLine::create([
                'dispensation_id' => $dispensation->id, 'genetic_id' => $batch->genetic_id, 'batch_id' => $batch->id,
                'grams_cg' => $gramsCg, 'price_per_gram_cents' => $pricePerGram, 'discount_cents' => 0,
                'line_total_cents' => $lineTotal, 'genetic_name_snapshot' => $batch->genetic->name, 'batch_no_snapshot' => $batch->batch_no,
            ]);

            $batch->remaining_cg = Weight::fromCentigrams($batch->remaining_cg->centigrams - $gramsCg);
            $batch->save();

            StockMovement::create([
                'organisation_id' => $orgId, 'location_id' => $location->id,
                'stockable_type' => Batch::class, 'stockable_id' => $batch->id,
                'qty_cg' => -$gramsCg, 'type' => StockMovementType::DISPENSE,
                'operator_id' => $operator->id, 'reference' => $dispensation->id,
            ]);

            if ($wallet > 0) {
                WalletTransaction::create([
                    'organisation_id' => $orgId, 'member_id' => $member->id, 'location_id' => $location->id,
                    'amount_cents' => -$wallet, 'type' => WalletTransactionType::CONTRIBUTION,
                    'balance_after_cents' => $members[$index]['balance'], 'operator_id' => $operator->id,
                    'till_session_id' => $till->id, 'source_type' => Dispensation::class, 'source_id' => $dispensation->id,
                ]);
            }

            $cashTaken += $cash;
        }

        return $cashTaken;
    }

    private function seedOrders(string $orgId, Location $location, TillSession $till, Carbon $day, User $operator): int
    {
        $cashTaken = 0;
        $articles = Article::where('location_id', $location->id)->get();
        if ($articles->isEmpty()) {
            return 0;
        }

        for ($i = 0; $i < random_int(1, 3); $i++) {
            $article = $articles->random();
            $qty = random_int(1, 3);

            // Through the REAL writer (never hand-build a shape a domain action owns — see CLAUDE.md).
            // CommitOrder builds the item snapshot (article_id + unit_price_cents + line_total_cents that
            // BarSalesReport reads), depletes UNIT stock via a SALE movement, and posts cash. Hand-building
            // the JSON `items` as {name, qty, price_cents} was why the Bar sales report read €0 on seeded data.
            $order = (new CommitOrder)->handle($location, [
                ['article_id' => $article->id, 'qty' => $qty],
            ], [
                'operator_id' => $operator->id,
                'till_session_id' => $till->id,
                'idempotency_key' => (string) Str::ulid(),
            ]);

            $cashTaken += $order->cash_cents->cents;
        }

        return $cashTaken;
    }
}
