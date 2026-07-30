<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Support\ActiveScope;
use App\Support\MemberEligibility;
use App\Support\MemberNumber;
use OpenSpout\Reader\CSV\Reader;

/**
 * Import the club's existing member list from CSV. `preview()` is a dry-run:
 * per-row validation + duplicate detection against the directory, writing nothing.
 * `import()` creates the valid, non-duplicate rows and is idempotent on re-run
 * (existing members are skipped by the duplicate guard). Both audited.
 *
 * Expected header columns: first_name, last_name, email, phone, date_of_birth,
 * document_type, document_number, declared_monthly_g.
 */
class ImportMembers
{
    /** @return array{created: int, skipped: int, errors: array<int, array<int, string>>} */
    public function preview(string $path): array
    {
        return $this->process($path, dryRun: true);
    }

    /** @return array{created: int, skipped: int, errors: array<int, array<int, string>>} */
    public function import(string $path): array
    {
        $result = $this->process($path, dryRun: false);

        (new RecordAuditLog)->handle('members.imported', null, null, $result);

        return $result;
    }

    /** @return array{created: int, skipped: int, errors: array<int, array<int, string>>} */
    private function process(string $path, bool $dryRun): array
    {
        $organisationId = app(ActiveScope::class)->organisationId();
        $finder = new FindDuplicateMembers;

        $created = 0;
        $skipped = 0;
        $errors = [];
        $header = null;
        $rowNumber = 0;

        $reader = new Reader;
        $reader->open($path);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowNumber++;
                $cells = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row->toArray());

                if ($header === null) {
                    $header = $cells;

                    continue;
                }

                /** @var array<string, mixed> $data */
                $data = @array_combine($header, array_pad($cells, count($header), null));

                if ($finder->handle($data)->isNotEmpty()) {
                    $skipped++;   // already in the directory — idempotent

                    continue;
                }

                $rowErrors = $this->validate($data);
                if ($rowErrors !== []) {
                    $errors[$rowNumber] = $rowErrors;

                    continue;
                }

                if (! $dryRun) {
                    $declaredG = $data['declared_monthly_g'] ?? null;

                    Member::create([
                        'organisation_id' => $organisationId,
                        'member_no' => MemberNumber::next($organisationId),
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'email' => $data['email'] ?: null,
                        'phone' => $data['phone'] ?: null,
                        'date_of_birth' => $data['date_of_birth'] ?: null,
                        'document_type' => $data['document_type'] ?: null,
                        'document_number' => $data['document_number'] ?: null,
                        'declared_monthly_cg' => is_numeric($declaredG) ? (int) round(((float) $declaredG) * 100) : null,
                        'status' => MemberStatus::ACTIVE,
                        'joined_at' => now(),
                        'carencia_ends_at' => now()->subDay(), // existing members: carencia already served
                    ]);
                }

                $created++;
            }
        }

        $reader->close();

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function validate(array $data): array
    {
        $errors = [];

        if (blank($data['first_name'] ?? null) || blank($data['last_name'] ?? null)) {
            $errors[] = 'name required';
        }

        if (! MemberEligibility::isOldEnough($data['date_of_birth'] ?? null)) {
            $errors[] = 'below minimum age or missing DOB';
        }

        return $errors;
    }
}
