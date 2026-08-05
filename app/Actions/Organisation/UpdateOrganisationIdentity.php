<?php

namespace App\Actions\Organisation;

use App\Actions\RecordAuditLog;
use App\Models\Organisation;
use Illuminate\Support\Facades\DB;

/**
 * The ONE writer for an organisation's editable identity COLUMNS (prompt 159): trading name, legal name,
 * CIF/NIF, registered address, contact email + phone, logo. Everything reads back through OrganisationIdentity
 * (the single source), so the statutory PDFs and the club-branded email letterhead stay in agreement — this
 * writer never introduces a second identity path.
 *
 * The consent declarations are NOT here: they are per-locale SETTINGS with their own version rule and their own
 * owner-level permission (`settings.consent`, prompt 153), edited on ManageConsentText via UpdateConsentText.
 * Keeping them apart respects that deliberate separation rather than folding a legal-content capability into the
 * routine identity screen.
 *
 * `legal_name` may change even after statutory documents exist: those documents are immutable snapshots and are
 * NOT rewritten (the libro de socios said what it said); the change is recorded here in the audit log with both
 * values. Refusing would wedge a genuine typo fix for every FUTURE document, which is the worse failure.
 */
class UpdateOrganisationIdentity
{
    /**
     * @param  array<string, mixed>  $attributes  Organisation columns to fill (fillable-guarded by the model).
     */
    public function handle(Organisation $organisation, array $attributes): Organisation
    {
        return DB::transaction(function () use ($organisation, $attributes): Organisation {
            $before = $this->snapshot($organisation);

            $organisation->fill($attributes)->save();
            $organisation->refresh();

            (new RecordAuditLog)->handle('organisation.identity.updated', $organisation, $before, $this->snapshot($organisation));

            return $organisation;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Organisation $organisation): array
    {
        return [
            'name' => $organisation->name,
            'legal_name' => $organisation->legal_name,
            'tax_id' => $organisation->tax_id,
            'address' => $organisation->address,
            'contact_email' => $organisation->contact_email,
            'contact_phone' => $organisation->contact_phone,
            'logo_path' => $organisation->logo_path,
        ];
    }
}
