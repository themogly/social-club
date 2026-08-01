<?php

namespace App\Filament\Concerns;

use App\Actions\RecordAuditLog;
use Filament\Resources\Pages\EditRecord;

/**
 * Audit a Filament resource edit as a real before/after diff (prompt 48): only the CHANGED
 * attributes are captured (never the whole model), and credential material is never included.
 * Prompt 40's AuditFieldFormatter/Labeler render the diff in plain language. Capture the diff in
 * beforeSave (getOriginal is synced away once the model saves) and write it in afterSave.
 *
 * @mixin EditRecord
 */
trait AuditsResourceChanges
{
    /**
     * Attributes never placed in an audit diff — credentials/secrets, special-category (Art. 9) data, and
     * noise. `is_therapeutic` is a health flag; `document_hash` is an UNSALTED index of the DNI (a lookup
     * table from the original) — neither belongs in the longer-retained audit log (prompt 76).
     */
    private const AUDIT_SENSITIVE = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'is_therapeutic', 'document_hash', 'updated_at'];

    /** @var array<string, mixed> raw original attributes, snapshotted before the save */
    private array $auditOriginal = [];

    /**
     * Snapshot the raw original BEFORE the save. Filament fills the record in handleRecordUpdate
     * (after this hook), and save() then syncs the original away — so this is the only point the
     * pre-edit values are still available.
     */
    protected function captureAuditDiff(): void
    {
        $this->auditOriginal = $this->getRecord()->getRawOriginal();
    }

    protected function writeAuditLog(string $action): void
    {
        $before = [];
        $after = [];

        // After the save, getRawOriginal() holds the NEW values (raw, cast-free — JSON-clean).
        foreach ($this->getRecord()->getRawOriginal() as $key => $newValue) {
            if (in_array($key, self::AUDIT_SENSITIVE, true)) {
                continue;
            }

            $oldValue = $this->auditOriginal[$key] ?? null;

            if ($oldValue != $newValue) {
                $before[$key] = $oldValue;
                $after[$key] = $newValue;
            }
        }

        if ($after !== []) {
            (new RecordAuditLog)->handle($action, $this->getRecord(), $before, $after);
        }
    }
}
