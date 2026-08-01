<?php

namespace Database\Seeders;

use App\Actions\Bar\CommitOrder;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Wallet\RecordWalletTransaction;
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
use App\Enums\FeePaymentMethod;
use App\Enums\IdDocumentType;
use App\Enums\MemberKind;
use App\Enums\MembershipPeriod;
use App\Enums\MemberStatus;
use App\Enums\SanctionType;
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
use App\Models\MemberSanction;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\Setting;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Local-only demo data: one org, two premises, tiers, the seeded staff, ~30
 * members across every status, genetics with per-location prices + batches
 * (opening stock as INTAKE movements), articles, discounts, and a fortnight of
 * dispensations, orders, check-ins, expenses and closed till sessions — so the
 * dashboard (prompt 14/15) has real data from day one. Opening balances always
 * enter through movements/adjustments, never free-typed (the go-live path).
 *
 * Prompt 70: everything is LOCALE-AWARE — the string set (locations, grades,
 * tiers, articles, expense categories) and the faker locale are both chosen from
 * the active app locale, so an English demo reads English and a Spanish one Spanish.
 * A demo SETTINGS PROFILE then switches the optional features on (as settings rows,
 * exactly as an admin would — Settings::DEFAULTS is never touched) and seeds the
 * data that makes each one legible (a member in debt, one near the limit, a
 * temporary member near expiry, fee payments so nobody is fee-blocked, a sanction).
 * New records go through their domain writer (EnrolMembership, RecordFeePayment,
 * RecordWalletTransaction, RecordStockMovement, CommitOrder) per the CLAUDE.md rule.
 */
class DemoDataSeeder extends Seeder
{
    private ActiveScope $scope;

    private FakerGenerator $faker;

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $this->scope = app(ActiveScope::class);

        // Pick the string set + faker locale from the ACTIVE app locale (prompt 70). Setting the config
        // makes any remaining factory `fake()` calls locale-match too; $this->faker is resolved from it.
        $strings = $this->localeStrings(app()->getLocale());
        config(['app.faker_locale' => $strings['faker']]);
        $this->faker = FakerFactory::create($strings['faker']);

        $org = Organisation::create([
            'name' => 'CSC platform',
            'legal_name' => 'TBD-LEGAL-NAME',      // OVERNIGHT-PLACEHOLDER — CONFIRM
            'tax_id' => 'TBD-CIF-NIF',             // OVERNIGHT-PLACEHOLDER — CONFIRM
            'address' => 'TBD-ADDRESS',            // OVERNIGHT-PLACEHOLDER — CONFIRM
            'contact_email' => 'info@club.test',
        ]);
        $this->scope->setOrganisation($org->id);

        $this->seedSettings($org->id);
        $this->seedDemoProfile();

        $centro = Location::create([
            'organisation_id' => $org->id, 'name' => $strings['locations'][0], 'address' => 'TBD-ADDRESS',
            'capacity' => 50, 'timezone' => 'Europe/Madrid', 'business_day_cutoff' => '06:00',
            'opening_time' => '12:00', 'closing_time' => '00:00', 'accent' => '#2563eb', 'active' => true,
        ]);
        $norte = Location::create([
            'organisation_id' => $org->id, 'name' => $strings['locations'][1], 'address' => 'TBD-ADDRESS',
            'capacity' => 40, 'timezone' => 'Europe/Madrid', 'business_day_cutoff' => '06:00',
            'opening_time' => '17:00', 'closing_time' => '02:00', 'accent' => '#16a34a', 'active' => true,
        ]);
        $locations = [$centro, $norte];

        $staff = $this->attachStaff($locations);

        $tierSocio = MembershipTier::create([
            'organisation_id' => $org->id, 'name' => $strings['tiers']['standard'], 'default_fee_cents' => 2000,
            'default_period' => MembershipPeriod::YEARLY, 'active' => true,
        ]);
        $tierTera = MembershipTier::create([
            'organisation_id' => $org->id, 'name' => $strings['tiers']['therapeutic'], 'default_fee_cents' => 1000,
            'default_period' => MembershipPeriod::YEARLY, 'active' => true,
        ]);

        // Genetic categories are a house GRADING (prompt 66) — orthogonal to strain type (sativa/indica/
        // hybrid) and product type, so the three POS filter rows select genuinely different sets instead
        // of one redundant "Flores" on every flower (which is what made them read as duplicates).
        $geneticCategories = [
            'premium' => Category::create(['organisation_id' => $org->id, 'name' => $strings['grades']['premium'], 'applies_to' => CategoryAppliesTo::GENETIC]),
            'standard' => Category::create(['organisation_id' => $org->id, 'name' => $strings['grades']['standard'], 'applies_to' => CategoryAppliesTo::GENETIC]),
        ];
        $catBar = Category::create(['organisation_id' => $org->id, 'name' => $strings['bar_category'], 'applies_to' => CategoryAppliesTo::ARTICLE]);

        ExpenseCategorySeeder::seedFor($org->id, app()->getLocale());
        // The petty-cash category is identified by KIND (TILL), never by its localised name (prompt 70).
        $pettyCashCat = ExpenseCategory::where('default_kind', ExpenseKind::TILL)->first();

        Discount::create(['organisation_id' => $org->id, 'name' => $strings['discounts']['staff'], 'kind' => DiscountKind::STAFF, 'mode' => DiscountMode::PERCENT, 'value_bp' => 1000, 'applies_to' => DiscountAppliesTo::BOTH, 'active' => true]);
        Discount::create(['organisation_id' => $org->id, 'name' => $strings['discounts']['therapeutic'], 'kind' => DiscountKind::THERAPEUTIC, 'mode' => DiscountMode::PERCENT, 'value_bp' => 1500, 'applies_to' => DiscountAppliesTo::GENETIC, 'active' => true]);

        [$batchesByLocation, $priceByBatch] = $this->seedCatalogue($org->id, $locations, $geneticCategories, $catBar, $staff, $strings);

        $membersByLocation = $this->seedMembers($org->id, $locations, $tierSocio, $tierTera, $staff, $strings);

        $this->seedFortnight($org->id, $locations, $staff, $batchesByLocation, $priceByBatch, $membersByLocation, $pettyCashCat);
    }

    /**
     * The keyed string set + faker locale for the active locale (prompt 70). This is DATA written once,
     * not UI rendered per request, so it deliberately does not go through lang/ files. Strain names stay
     * as-is everywhere — they are proper nouns, identical in every language.
     *
     * @return array{faker: string, locations: array{0: string, 1: string}, grades: array{premium: string, standard: string}, bar_category: string, tiers: array{standard: string, therapeutic: string}, discounts: array{staff: string, therapeutic: string}, articles: array<string, int>, opening_stock: string, opening_balance: string, seed_debt: string}
     */
    private function localeStrings(string $locale): array
    {
        $sets = [
            'es' => [
                'faker' => 'es_ES',
                'locations' => ['Sede Centro', 'Sede Norte'],
                'grades' => ['premium' => 'Premium', 'standard' => 'Estándar'],
                'bar_category' => 'Bar',
                'tiers' => ['standard' => 'Socio', 'therapeutic' => 'Terapéutico'],
                'discounts' => ['staff' => 'Personal', 'therapeutic' => 'Terapéutico'],
                'articles' => ['Agua' => 150, 'Refresco' => 200, 'Café' => 120, 'Mechero' => 100, 'Papel de liar' => 90],
                'opening_stock' => 'Existencias iniciales',
                'opening_balance' => 'Saldo inicial (importación go-live)',
                'seed_debt' => 'Saldo deudor (demo)',
            ],
            'en' => [
                'faker' => 'en_GB',
                'locations' => ['Central Branch', 'North Branch'],
                'grades' => ['premium' => 'Premium', 'standard' => 'Standard'],
                'bar_category' => 'Bar',
                'tiers' => ['standard' => 'Member', 'therapeutic' => 'Therapeutic'],
                'discounts' => ['staff' => 'Staff', 'therapeutic' => 'Therapeutic'],
                'articles' => ['Water' => 150, 'Soft drink' => 200, 'Coffee' => 120, 'Lighter' => 100, 'Rolling papers' => 90],
                'opening_stock' => 'Opening stock',
                'opening_balance' => 'Opening balance (go-live import)',
                'seed_debt' => 'Outstanding balance (demo)',
            ],
        ];

        return $sets[$locale] ?? $sets['en'];
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
     * Switch the optional features ON — as SETTINGS ROWS, exactly as an admin would, so Settings::DEFAULTS
     * (the conservative production posture) is never touched (prompt 70, rule 1). Enables wallet debt (with
     * a real €50 cap and a lower €30 door threshold, so a member can be past the door limit yet within the
     * counter limit — both blocks are visible), temporary members, camera scanning and the dispensation
     * signature. restrict_pos_to_checked_in / discounts_stack / ring_fenced are LEFT OFF on purpose — each
     * changes counter behaviour in a way that would confuse the demo without heavier supporting data; the
     * reasoning is in DECISIONS.md.
     */
    private function seedDemoProfile(): void
    {
        Settings::set('wallet_debt_allowed', '1', SettingType::BOOL);
        Settings::set('wallet_debt_limit_cents', '5000', SettingType::INT);          // €50 counter cap
        Settings::set('wallet_door_debt_threshold_cents', '3000', SettingType::INT); // €30 door threshold
        Settings::set('temporary_members_enabled', '1', SettingType::BOOL);
        Settings::set('camera_scan_enabled', '1', SettingType::BOOL);
        Settings::set('signature_on_dispensation', '1', SettingType::BOOL);
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
     * @param  array<string, Category>  $geneticCategories
     * @param  array{owner: User, manager: User, staff: User}  $staff
     * @param  array{faker: string, locations: array{0: string, 1: string}, grades: array{premium: string, standard: string}, bar_category: string, tiers: array{standard: string, therapeutic: string}, discounts: array{staff: string, therapeutic: string}, articles: array<string, int>, opening_stock: string, opening_balance: string, seed_debt: string}  $strings
     * @return array{0: array<string, array<int, Batch>>, 1: array<string, int>}
     */
    private function seedCatalogue(string $orgId, array $locations, array $geneticCategories, Category $catBar, array $staff, array $strings): array
    {
        // [name, thc_bp, cbd_bp, cultivation, strain type, grade key] — strain + grade are spread
        // ORTHOGONALLY (prompt 66): sativa/indica/hybrid across both grades, plus a CBD variety with NO
        // strain type. So Variedad, Categoría and Tipo select different sets. Strain names are proper nouns.
        $definitions = [
            ['Amnesia Haze', 2200, 50, CultivationType::INDOOR, StrainType::SATIVA, 'premium'],
            ['Critical Kush', 1900, 80, CultivationType::INDOOR, StrainType::INDICA, 'standard'],
            ['CBD Charlotte', 600, 1200, CultivationType::GREENHOUSE, null, 'standard'],
            ['Moby Dick', 2300, 40, CultivationType::OUTDOOR, StrainType::SATIVA, 'premium'],
            ['Northern Lights', 1800, 60, CultivationType::INDOOR, StrainType::INDICA, 'standard'],
            ['Purple Haze', 2000, 30, CultivationType::OUTDOOR, StrainType::HYBRID, 'premium'],
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
                    'tier_id' => null, 'price_per_gram_cents' => $pricePerGram,
                    // A SHARED eighth price (prompt 90) — the same €23 across every weight strain at the sede,
                    // so the feature is visible on a fresh install and a 1.75 g + 1.75 g basket across two
                    // strains exercises the cross-strain split immediately. €23 < 3.5 × €7 (the lowest
                    // per-gram), so it is always a genuine break, never overriding the floor.
                    'price_per_eighth_cents' => 2300,
                    'low_stock_threshold_cg' => 5000, 'active' => true,
                ]);

                $initial = random_int(20000, 80000); // cg
                // Batch starts EMPTY; opening stock enters through the single stock writer as an INTAKE
                // movement (the real go-live path — never a free-typed remaining_cg).
                $batch = Batch::create([
                    'organisation_id' => $orgId, 'genetic_id' => $genetic->id, 'location_id' => $location->id,
                    'batch_no' => 'B-'.strtoupper(Str::random(6)), 'acquired_or_harvested_on' => now()->subDays(30),
                    'initial_cg' => $initial, 'remaining_cg' => 0, 'cost_per_gram_cents' => random_int(300, 600),
                    'status' => BatchStatus::OPEN,
                ]);

                (new RecordStockMovement)->handle($batch, StockMovementType::INTAKE, $initial, [
                    'operator_id' => $staff['owner']->id,
                    'reason' => $strings['opening_stock'],
                    'reference' => $batch->batch_no,
                ]);

                $batchesByLocation[$location->id][] = $batch->refresh();
                $priceByBatch[$batch->id] = $pricePerGram;
            }
        }

        foreach ($locations as $location) {
            $this->scope->setLocation($location->id);
            foreach ($strings['articles'] as $name => $cents) {
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
     * @param  array{owner: User, manager: User, staff: User}  $staff
     * @param  array{faker: string, locations: array{0: string, 1: string}, grades: array{premium: string, standard: string}, bar_category: string, tiers: array{standard: string, therapeutic: string}, discounts: array{staff: string, therapeutic: string}, articles: array<string, int>, opening_stock: string, opening_balance: string, seed_debt: string}  $strings
     * @return array<string, array<int, array{member: Member, balance: int}>>
     */
    private function seedMembers(string $orgId, array $locations, MembershipTier $tierSocio, MembershipTier $tierTera, array $staff, array $strings): array
    {
        $membersByLocation = [];
        $number = 1;
        $operatorId = $staff['manager']->id;

        // 16 active members split across the two locations. Each is enrolled through EnrolMembership and has
        // its fee PAID through RecordFeePayment (prompt 46's unpaid_fee block would otherwise stop the demo
        // dispensing to anyone). A few carry an opening wallet balance via RecordWalletTransaction.
        for ($i = 0; $i < 16; $i++) {
            $location = $locations[$i % count($locations)];
            $this->scope->setLocation($location->id);

            $member = $this->makeMember($orgId, $number++, MemberStatus::ACTIVE, $i < 3);
            $tier = $member->is_therapeutic ? $tierTera : $tierSocio;

            $membership = (new EnrolMembership)->handle($member, $location, $tier, [
                'starts_at' => now()->subMonths(6), 'actor' => $staff['owner'],
            ]);
            (new RecordFeePayment)->handle($membership, $tier->default_fee_cents->cents, FeePaymentMethod::CASH, [
                'operator_id' => $operatorId,
            ]);

            $balance = random_int(0, 6000);
            if ($balance > 0) {
                (new RecordWalletTransaction)->handle($member, $location, $balance, WalletTransactionType::ADJUSTMENT, [
                    'operator_id' => $operatorId, 'reason' => $strings['opening_balance'],
                ]);
            }

            $membersByLocation[$location->id][] = ['member' => $member, 'balance' => $balance];
        }

        // One member of each non-active status — so every status badge/filter has a real example.
        foreach ([MemberStatus::APPLICANT, MemberStatus::APPLICANT, MemberStatus::INACTIVE, MemberStatus::EXPIRED, MemberStatus::SUSPENDED, MemberStatus::EXPELLED] as $status) {
            $this->makeMember($orgId, $number++, $status, false);
        }

        // Feature-exercising members (prompt 70) — a flag with no data demonstrates nothing. All are at the
        // first location, active and fee-paid, so the ONLY thing each demonstrates is its named feature.
        $this->seedFeatureMembers($orgId, $locations[0], $tierSocio, $staff, $strings, $number);

        return $membersByLocation;
    }

    /**
     * @param  array{owner: User, manager: User, staff: User}  $staff
     * @param  array{faker: string, locations: array{0: string, 1: string}, grades: array{premium: string, standard: string}, bar_category: string, tiers: array{standard: string, therapeutic: string}, discounts: array{staff: string, therapeutic: string}, articles: array<string, int>, opening_stock: string, opening_balance: string, seed_debt: string}  $strings
     */
    private function seedFeatureMembers(string $orgId, Location $location, MembershipTier $tier, array $staff, array $strings, int $number): void
    {
        $this->scope->setLocation($location->id);
        $operatorId = $staff['manager']->id;

        $enrol = function (Member $member) use ($location, $tier, $staff, $operatorId): Membership {
            $membership = (new EnrolMembership)->handle($member, $location, $tier, [
                'starts_at' => now()->subMonths(3), 'actor' => $staff['owner'],
            ]);
            (new RecordFeePayment)->handle($membership, $tier->default_fee_cents->cents, FeePaymentMethod::CASH, ['operator_id' => $operatorId]);

            return $membership;
        };

        // In debt within the €50 counter cap (−€40): past the €30 door threshold, so the door blocks but the
        // counter allows — the balance display, door threshold and counter block are all visible at once.
        $inDebt = $this->makeMember($orgId, $number++, MemberStatus::ACTIVE, false);
        $enrol($inDebt);
        (new RecordWalletTransaction)->handle($inDebt, $location, -4000, WalletTransactionType::ADJUSTMENT, [
            'operator_id' => $operatorId, 'reason' => $strings['seed_debt'], 'allow_debt' => true,
        ]);

        // Near the cap (−€48) — the next contribution tips them over.
        $nearLimit = $this->makeMember($orgId, $number++, MemberStatus::ACTIVE, false);
        $enrol($nearLimit);
        (new RecordWalletTransaction)->handle($nearLimit, $location, -4800, WalletTransactionType::ADJUSTMENT, [
            'operator_id' => $operatorId, 'reason' => $strings['seed_debt'], 'allow_debt' => true,
        ]);

        // Temporary member approaching expiry (3 days out) — the removal-reminder path is visible.
        $temp = $this->makeMember($orgId, $number++, MemberStatus::ACTIVE, false);
        $temp->update(['kind' => MemberKind::TEMPORARY, 'temporary_expires_at' => now()->addDays(3)]);
        $enrol($temp);

        // In carencia (ends in 5 days) — may enter, may not dispense (the carencia block is legible).
        $carencia = $this->makeMember($orgId, $number++, MemberStatus::ACTIVE, false);
        $carencia->update(['carencia_ends_at' => now()->addDays(5)]);
        $enrol($carencia);

        // Membership expiring soon (5 days) — the renewal-reminder / expiring-soon filter has an example.
        $expiring = $this->makeMember($orgId, $number++, MemberStatus::ACTIVE, false);
        $expMembership = $enrol($expiring);
        $expMembership->update(['expires_at' => now()->addDays(5)]);

        // Active member carrying a warning sanction — so prompt 51's sanctions tab is not empty.
        $sanctioned = $this->makeMember($orgId, $number++, MemberStatus::ACTIVE, false);
        $enrol($sanctioned);
        MemberSanction::create([
            'member_id' => $sanctioned->id, 'type' => SanctionType::WARNING,
            'reason' => $strings['seed_debt'] === 'Outstanding balance (demo)' ? 'Late fee reminder (demo)' : 'Aviso por retraso de cuota (demo)',
            'from_date' => now()->subDays(2), 'until_date' => now()->addDays(28), 'recorded_by' => $operatorId,
        ]);
    }

    private function makeMember(string $orgId, int $number, MemberStatus $status, bool $therapeutic): Member
    {
        return Member::create([
            'organisation_id' => $orgId,
            'member_no' => sprintf('M-%05d', $number),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->numerify('6########'),
            'date_of_birth' => $this->faker->dateTimeBetween('-60 years', '-21 years'),
            'address' => $this->faker->streetAddress(),
            'document_type' => IdDocumentType::DNI,
            'document_number' => $this->faker->numerify('########').'Z',
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

            // NB: the fortnight's dispensations stay relational-with-full-snapshot — the documented
            // compliance-boundary carve-out (CLAUDE.md): CommitDispensation would REJECT historical demo
            // data (carencia/limits/fees for a back-dated day), so the seeder writes the completed shape
            // directly, populating every column the real writer sets. The LIVE path is exercised by the
            // fee-paid members above (a fresh CommitDispensation succeeds — see DemoSeedProfileTest).
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

            // Stock leaves through the single writer (a DISPENSE movement + the locked decrement). Refresh
            // the local batch after — the writer decremented it in the DB, and the next iteration's
            // remaining_cg guard must see the new figure, not a stale one.
            (new RecordStockMovement)->handle($batch, StockMovementType::DISPENSE, -$gramsCg, [
                'operator_id' => $operator->id, 'reference' => $dispensation->id,
            ]);
            $batch->refresh();

            if ($wallet > 0) {
                (new RecordWalletTransaction)->handle($member, $location, -$wallet, WalletTransactionType::CONTRIBUTION, [
                    'operator_id' => $operator->id, 'till_session_id' => $till->id, 'source' => $dispensation, 'allow_debt' => true,
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
            // Re-read live so a fortnight of seeded sales never asks CommitOrder for more than the article
            // has left (it refuses, rightly) — pick only articles still in stock and bound the qty to it.
            $article = Article::where('location_id', $location->id)->where('stock', '>', 0)->inRandomOrder()->first();
            if ($article === null) {
                break; // everything sold out for the day
            }
            $qty = min(random_int(1, 3), (int) $article->stock);

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
