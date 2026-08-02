<?php

namespace App\Actions\Members;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\RecordAuditLog;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Support\ActiveScope;
use App\Support\MemberEligibility;
use App\Support\MemberNumber;
use App\Support\StockCeiling;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Reader;
use Throwable;

/**
 * Import the club's existing member list from CSV — the migration off a paper libro de socios.
 *
 * `preview()` is a real dry run (per-row validation + duplicate/clash detection, writing nothing) that reports
 * everything a club needs to see BEFORE committing: how many rows, duplicates, errors, member-number clashes,
 * how many rows arrive without consent, and — the number that stops a club manufacturing stock headroom it is
 * not entitled to — the resulting active membership per sede and the on-site stock ceiling that implies
 * (prompt 110). `import()` writes the valid, non-duplicate rows in ONE transaction (atomic: a partial failure
 * leaves nothing behind) and is idempotent on re-run (the duplicate guard skips anyone already imported).
 * Both are audited.
 *
 * The paper book records two facts the old import discarded — the real join date (*alta*) and the member's own
 * number — and the register the system prints must agree with the book, so the CSV carries them. It also carries
 * the sede/tier that makes an imported member actually servable, and the date+version of the consent the member
 * signed on paper. Every new column is OPTIONAL with today's behaviour as the fallback, so an existing CSV of
 * the original columns imports exactly as before.
 *
 * Header columns (all optional except the name; unknown/absent columns fall back):
 *   first_name, last_name, email, phone, date_of_birth, document_type, document_number, declared_monthly_g,
 *   member_no, joined_at, left_at, status, location, tier, membership_start, consent_date, consent_text_version
 *
 * @phpstan-type ImportResult array{created: int, skipped: int, errors: array<int, array<int, string>>, consent_pending: int, ceilings: array<string, array{location: string, added_active: int, active_members: int, ceiling_cg: int, current_active: int, current_ceiling_cg: int}>}
 */
class ImportMembers
{
    /** @var Collection<string, Location>|null Locations keyed by lower-cased name (per import, resolved once). */
    private ?Collection $locations = null;

    /** @var Collection<string, MembershipTier>|null Tiers keyed by lower-cased name. */
    private ?Collection $tiers = null;

    /** @return ImportResult */
    public function preview(string $path): array
    {
        return $this->process($path, dryRun: true);
    }

    /** @return ImportResult */
    public function import(string $path): array
    {
        $result = $this->process($path, dryRun: false);

        (new RecordAuditLog)->handle('members.imported', null, null, [
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'errors' => count($result['errors']),
            'consent_pending' => $result['consent_pending'],
        ]);

        return $result;
    }

    /** @return ImportResult */
    private function process(string $path, bool $dryRun): array
    {
        $organisationId = app(ActiveScope::class)->organisationId();

        $rows = $this->readRows($path);
        $duplicateNos = $this->duplicateMemberNumbers($rows);
        $existingNos = Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->pluck('member_no')->filter()->map(fn ($n) => trim((string) $n))->all();

        $finder = new FindDuplicateMembers;

        /** @var array<int, array<string, mixed>> $plans */
        $plans = [];
        $errors = [];
        $skipped = 0;

        foreach ($rows as [$rowNumber, $data]) {
            if ($this->isBlankRow($data)) {
                continue; // an empty trailing line is not an error
            }

            if ($finder->handle($data)->isNotEmpty()) {
                $skipped++;   // already in the directory — idempotent

                continue;
            }

            $rowErrors = $this->validate($data, $organisationId, $duplicateNos, $existingNos);
            if ($rowErrors !== []) {
                $errors[$rowNumber] = $rowErrors;

                continue;
            }

            $plans[] = $this->plan($data);
        }

        $result = [
            'created' => count($plans),
            'skipped' => $skipped,
            'errors' => $errors,
            'consent_pending' => count(array_filter($plans, fn (array $p): bool => $p['consent'] === null)),
            'ceilings' => $this->projectCeilings($plans),
        ];

        if (! $dryRun && $plans !== []) {
            $this->write($plans, $organisationId);
        }

        return $result;
    }

    /**
     * Write every planned row in ONE transaction — a partial failure rolls the whole run back rather than
     * leaving half a club imported (the alternative, resumability, is already covered by the duplicate guard on
     * re-run; atomicity is the stronger guarantee and the one that matters mid-migration).
     *
     * @param  array<int, array<string, mixed>>  $plans
     */
    private function write(array $plans, string $organisationId): void
    {
        DB::transaction(function () use ($plans, $organisationId): void {
            // Fast-forward the org counter PAST the highest imported number BEFORE allocating any fresh numbers,
            // so a blank-member_no row's next() can never collide with a number the same import placed, and the
            // sequence is never left below an imported one (prompt 131).
            $maxImported = 0;
            foreach ($plans as $plan) {
                if ($plan['member_no'] !== null) {
                    $maxImported = max($maxImported, MemberNumber::parseSequence($plan['member_no']) ?? 0);
                }
            }
            if ($maxImported > 0) {
                MemberNumber::advanceAtLeast($organisationId, $maxImported);
            }

            foreach ($plans as $plan) {
                /** @var array<string, mixed> $data */
                $data = $plan['data'];
                $declaredG = $data['declared_monthly_g'] ?? null;

                $member = Member::create([
                    'organisation_id' => $organisationId,
                    'member_no' => $plan['member_no'] ?? MemberNumber::next($organisationId),
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => ($data['email'] ?? null) ?: null,
                    'phone' => ($data['phone'] ?? null) ?: null,
                    'date_of_birth' => ($data['date_of_birth'] ?? null) ?: null,
                    'document_type' => ($data['document_type'] ?? null) ?: null,
                    'document_number' => ($data['document_number'] ?? null) ?: null,
                    'declared_monthly_cg' => is_numeric($declaredG) ? (int) round_half_up(((float) $declaredG) * 100) : null,
                    'status' => $plan['member_status'],
                    'joined_at' => $plan['joined_at'],
                    'left_at' => $plan['left_at'],
                    'carencia_ends_at' => now()->subDay(), // existing members: carencia already served (do not regress)
                ]);

                if ($plan['location'] instanceof Location && $plan['tier'] instanceof MembershipTier) {
                    (new EnrolMembership)->handle($member, $plan['location'], $plan['tier'], [
                        'starts_at' => $plan['membership_start'] ?? $plan['joined_at'],
                        'status' => $plan['membership_status'],
                    ]);
                }

                // Consent is recorded ONLY when the CSV carried the version the member actually signed — never
                // defaulted to the current digital text (recording agreement to an unseen text reads very badly
                // in an inspection). A row with none imports and is left visibly consent-pending.
                if ($plan['consent'] !== null) {
                    (new RecordMemberConsent)->handle($member, 'membership', null, $plan['consent']['version'], $plan['consent']['date']);
                }
            }
        });
    }

    /**
     * Build the write plan for a validated row: resolve the paper facts (number, alta, sede/tier, consent) with
     * today's defaults for anything absent. The membership is ACTIVE only when the member is ACTIVE, so importing
     * a lapsed member does not silently raise the sede's stock ceiling.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function plan(array $data): array
    {
        $memberStatus = $this->parseMemberStatus($data['status'] ?? null) ?? MemberStatus::ACTIVE;
        $location = $this->resolveLocation($data['location'] ?? null);
        $tier = $this->resolveTier($data['tier'] ?? null);

        $consentDate = $this->parseDate($data['consent_date'] ?? null);
        $consentVersion = filled($data['consent_text_version'] ?? null) ? trim((string) $data['consent_text_version']) : null;

        return [
            'data' => $data,
            'member_no' => filled($data['member_no'] ?? null) ? trim((string) $data['member_no']) : null,
            'member_status' => $memberStatus,
            'joined_at' => $this->parseDate($data['joined_at'] ?? null) ?? now(),
            'left_at' => $this->parseDate($data['left_at'] ?? null),
            'location' => $location,
            'tier' => $tier,
            'membership_start' => $this->parseDate($data['membership_start'] ?? null),
            'membership_status' => $memberStatus === MemberStatus::ACTIVE ? MembershipStatus::ACTIVE : MembershipStatus::LAPSED,
            'consent' => ($consentDate !== null && $consentVersion !== null)
                ? ['date' => $consentDate, 'version' => $consentVersion]
                : null,
        ];
    }

    /**
     * Project the resulting active membership per sede and the stock ceiling it implies — from the CURRENT DB
     * figure plus the rows this import would make active (member ACTIVE + an ACTIVE membership at that sede,
     * exactly what {@see StockCeiling} counts). Reuses StockCeiling's own per-location settings so the ceiling
     * arithmetic is never forked. Writes nothing.
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @return array<string, array{location: string, added_active: int, active_members: int, ceiling_cg: int, current_active: int, current_ceiling_cg: int}>
     */
    private function projectCeilings(array $plans): array
    {
        /** @var array<string, int> $addedByLocation */
        $addedByLocation = [];
        foreach ($plans as $plan) {
            if ($plan['location'] instanceof Location
                && $plan['member_status'] === MemberStatus::ACTIVE
                && $plan['membership_status'] === MembershipStatus::ACTIVE) {
                $id = $plan['location']->id;
                $addedByLocation[$id] = ($addedByLocation[$id] ?? 0) + 1;
            }
        }

        $ceilings = [];
        foreach ($addedByLocation as $locationId => $added) {
            $location = $this->locationById($locationId);
            if ($location === null) {
                continue;
            }
            $current = StockCeiling::forLocation($location);
            $projectedActive = $current['active_members'] + $added;

            $ceilings[$locationId] = [
                'location' => $location->name,
                'added_active' => $added,
                'active_members' => $projectedActive,
                'ceiling_cg' => $projectedActive * $current['daily_limit_cg'] * $current['ceiling_days'],
                'current_active' => $current['active_members'],
                'current_ceiling_cg' => $current['ceiling_cg'],
            ];
        }

        return $ceilings;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $duplicateNos  member numbers appearing more than once in the file
     * @param  array<int, string>  $existingNos  member numbers already held in the org
     * @return array<int, string>
     */
    private function validate(array $data, string $organisationId, array $duplicateNos, array $existingNos): array
    {
        $errors = [];

        if (blank($data['first_name'] ?? null) || blank($data['last_name'] ?? null)) {
            $errors[] = 'name required';
        }

        if (! MemberEligibility::isOldEnough($data['date_of_birth'] ?? null)) {
            $errors[] = 'below minimum age or missing DOB';
        }

        // Member-number clash — surfaced by the PREVIEW, never a failure halfway through the import.
        if (filled($data['member_no'] ?? null)) {
            $number = trim((string) $data['member_no']);
            if (in_array($number, $duplicateNos, true)) {
                $errors[] = "member number '{$number}' appears more than once in the file";
            } elseif (in_array($number, $existingNos, true)) {
                $errors[] = "member number '{$number}' already belongs to another member";
            }
        }

        foreach (['joined_at', 'left_at', 'membership_start', 'consent_date'] as $dateField) {
            if (filled($data[$dateField] ?? null) && $this->parseDate($data[$dateField]) === null) {
                $errors[] = "invalid {$dateField} date";
            }
        }

        if (filled($data['status'] ?? null) && $this->parseMemberStatus($data['status']) === null) {
            $errors[] = "unknown status '".trim((string) $data['status'])."'";
        }

        // A membership needs BOTH a sede and a tier; a name that resolves to neither is a typo worth surfacing.
        $hasLocation = filled($data['location'] ?? null);
        $hasTier = filled($data['tier'] ?? null);
        if ($hasLocation !== $hasTier) {
            $errors[] = 'a membership needs both location and tier';
        }
        if ($hasLocation && $this->resolveLocation($data['location']) === null) {
            $errors[] = "unknown location '".trim((string) $data['location'])."'";
        }
        if ($hasTier && $this->resolveTier($data['tier']) === null) {
            $errors[] = "unknown tier '".trim((string) $data['tier'])."'";
        }

        // Consent is all-or-nothing: date AND version together, or neither. One without the other cannot be
        // recorded honestly (we will not invent the missing half), so it is a row error, not a silent default.
        $hasConsentDate = filled($data['consent_date'] ?? null);
        $hasConsentVersion = filled($data['consent_text_version'] ?? null);
        if ($hasConsentDate !== $hasConsentVersion) {
            $errors[] = 'consent needs both consent_date and consent_text_version';
        }

        return $errors;
    }

    /**
     * Member numbers that appear more than once across the file (normalised). A clash between two imported rows
     * is a preview error on both, not a runtime unique-index failure mid-import.
     *
     * @param  array<int, array{0: int, 1: array<string, mixed>}>  $rows
     * @return array<int, string>
     */
    private function duplicateMemberNumbers(array $rows): array
    {
        $counts = [];
        foreach ($rows as [, $data]) {
            if (filled($data['member_no'] ?? null)) {
                $number = trim((string) $data['member_no']);
                $counts[$number] = ($counts[$number] ?? 0) + 1;
            }
        }

        return array_keys(array_filter($counts, fn (int $c): bool => $c > 1));
    }

    /**
     * Read every data row into memory (a membership list is hundreds, not millions) so the import can validate
     * and report the whole file before writing a single row, and write atomically.
     *
     * @return array<int, array{0: int, 1: array<string, mixed>}>
     */
    private function readRows(string $path): array
    {
        $rows = [];
        /** @var list<string>|null $header */
        $header = null;
        $rowNumber = 0;

        $reader = new Reader;
        $reader->open($path);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowNumber++;
                $cells = array_map(fn ($v) => is_string($v) ? trim($v) : (string) $v, $row->toArray());

                if ($header === null) {
                    $header = array_map(fn ($h): string => trim((string) $h), $cells);

                    continue;
                }

                // Force the row to exactly the header width (pad short, trim long) so array_combine always
                // yields an array — a stray extra column is never a fatal ValueError mid-file.
                $width = count($header);
                $cells = array_pad(array_slice($cells, 0, $width), $width, '');
                $rows[] = [$rowNumber, array_combine($header, $cells)];
            }
        }

        $reader->close();

        return $rows;
    }

    /** @param  array<string, mixed>  $data */
    private function isBlankRow(array $data): bool
    {
        foreach ($data as $value) {
            if (filled($value)) {
                return false;
            }
        }

        return true;
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function parseMemberStatus(mixed $value): ?MemberStatus
    {
        if (blank($value)) {
            return null;
        }

        return MemberStatus::tryFrom(strtoupper(trim((string) $value)));
    }

    private function resolveLocation(mixed $name): ?Location
    {
        if (blank($name)) {
            return null;
        }

        $this->locations ??= Location::query()->withoutGlobalScopes()
            ->where('organisation_id', app(ActiveScope::class)->organisationId())
            ->get()->keyBy(fn (Location $l): string => mb_strtolower(trim((string) $l->name)));

        return $this->locations->get(mb_strtolower(trim((string) $name)));
    }

    private function locationById(string $id): ?Location
    {
        return $this->locations?->firstWhere('id', $id);
    }

    private function resolveTier(mixed $name): ?MembershipTier
    {
        if (blank($name)) {
            return null;
        }

        $this->tiers ??= MembershipTier::query()->withoutGlobalScopes()
            ->where('organisation_id', app(ActiveScope::class)->organisationId())
            ->get()->keyBy(fn (MembershipTier $t): string => mb_strtolower(trim((string) $t->name)));

        return $this->tiers->get(mb_strtolower(trim((string) $name)));
    }
}
