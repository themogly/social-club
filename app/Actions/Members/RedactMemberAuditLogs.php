<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\AuditLog;
use App\Models\Member;

/**
 * RGPD Art. 17 redaction of a member's existing audit rows (prompt 76). The audit log's retention is
 * deliberately LONGER than member data, so without this it would preserve — for a decade — exactly what
 * erasure was meant to remove: the health flag (`is_therapeutic`) and the unsalted DNI index
 * (`document_hash`), plus any direct PII captured in a before/after diff. This masks those VALUES in place.
 *
 * It is a first-class, AUDITED operation, not a hole in the append-only log: only the before/after payloads
 * are masked (the entry's actor/action/subject/date/IP are frozen — enforced by AuditLog's updating guard),
 * nothing is deleted, and the redaction itself is recorded (`member.audit.redacted`, count only).
 */
class RedactMemberAuditLogs
{
    /** Keys masked out of any before/after payload: the DNI index, the health flag, and direct identifiers. */
    private const SENSITIVE_KEYS = [
        'is_therapeutic', 'document_hash', 'document_number',
        'first_name', 'last_name', 'email', 'phone', 'address', 'date_of_birth',
        'nombre', 'documento', // snapshot-shaped keys
    ];

    public function handle(Member $member): int
    {
        $rows = AuditLog::query()
            ->where('auditable_type', $member->getMorphClass())
            ->where('auditable_id', $member->id)
            ->get();

        $redacted = 0;

        self::withRedaction(function () use ($rows, &$redacted): void {
            foreach ($rows as $row) {
                $before = $this->mask($row->before);
                $after = $this->mask($row->after);

                if ($before !== $row->before || $after !== $row->after) {
                    $row->before = $before;
                    $row->after = $after;
                    $row->save();
                    $redacted++;
                }
            }
        });

        (new RecordAuditLog)->handle('member.audit.redacted', $member, null, ['rows' => $redacted]);

        return $redacted;
    }

    /** Run $callback with the audit-log redaction bypass enabled, always resetting it. */
    public static function withRedaction(callable $callback): void
    {
        AuditLog::$redacting = true;

        try {
            $callback();
        } finally {
            AuditLog::$redacting = false;
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function mask(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        foreach (self::SENSITIVE_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[borrado]';
            }
        }

        return $payload;
    }
}
