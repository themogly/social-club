<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Enums\MemberDocumentType;
use App\Enums\MessageAuthor;
use App\Models\AssemblyAttendance;
use App\Models\ConvocatoriaRecipient;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageThread;
use Illuminate\Support\Facades\Storage;

/**
 * Right to erasure (RGPD Art. 17): anonymise-not-delete. Scrubs every personal field on the member ROW,
 * deletes every member-linked FILE from the private disk (ID scan, photo, MEDICAL certificate, generated
 * document PDFs), clears the health flag, redacts surviving document snapshots and the member's audit rows —
 * but KEEPS the financial/consumption LEDGER (dispensations, wallet, till, bar orders, check-ins) intact and
 * attributed to the anonymised record, so the books stay whole (prompt 76).
 *
 * Member-linked tables are ENUMERATED (below) rather than remembered — the guard test fails if a new one
 * appears uncovered. Every one except member_documents carries only a member_id REFERENCE + operational
 * (non-identity) fields, so scrubbing the member row erases the person from them; member_documents is the
 * exception, handled explicitly here (files + snapshots).
 *
 * Retention vs redaction (Art. 17(3)): a consumption DECLARATION and a REGISTRATION_FORM (libro de socios)
 * may carry a legal-retention obligation, so their ROW is RETAINED as minimal, non-identifying metadata (the
 * figure/version/date that a declaration existed) with the name + DNI REDACTED out of the snapshot and the
 * identifying PDF deleted; every other generated document is destroyed outright. So nothing survives that
 * identifies the member, while the club can still evidence that a member and their declaration existed.
 */
class AnonymiseMember
{
    /** Generated-document types with a legal-retention basis (Art. 17(3)) — retained REDACTED, not destroyed. */
    private const RETAINED_DOCUMENT_TYPES = [MemberDocumentType::DECLARATION, MemberDocumentType::REGISTRATION_FORM];

    /**
     * Every table holding a member_id, and WHY erasing the member row (or the explicit handling here) covers
     * it. The guard test enumerates member_id tables from the schema and fails if one is missing here — so a
     * new member-linked table cannot silently reopen the erasure gap.
     *
     * @var array<string, string>
     */
    public const COVERED_MEMBER_TABLES = [
        'member_documents' => 'HANDLED here: files deleted; retention-obligation docs redacted, others destroyed.',
        'member_tokens' => 'reference + hash-only credential (no PII); revoked here.',
        'member_login_tokens' => 'reference + hash-only credential (no PII).',
        'consent_records' => 'reference + consent version/date/locale (no name/DNI); the identity is the member row.',
        'member_sanctions' => 'reference + conduct free-text about the sanction, not identity.',
        'member_discounts' => 'reference + discount assignment (no PII).',
        'memberships' => 'reference + tier/dates/fee (no PII).',
        'check_ins' => 'reference + timestamps (no PII — prompt 52 verified).',
        'orders' => 'reference + item snapshot (no member PII — prompt 52 verified).',
        'wallet_transactions' => 'reference + amounts/reason (no name/DNI).',
        'event_rsvps' => 'reference + event id (no PII).',
        'data_requests' => 'reference + request type/status/dates (no name/DNI).',
        'dispensations' => 'reference + amounts/weights, the Art. 9 ledger kept intact (no name/DNI).',
        'refunds' => 'reference + amount/reason (no name/DNI).',
        'convocatoria_recipients' => 'HANDLED here: the frozen roll keeps the row + status as assembly evidence; the name/email SNAPSHOT it holds is redacted.',
        'message_threads' => 'HANDLED here: the thread + timestamps stay as evidence of contact; the member-authored subject is redacted and their message bodies scrubbed (messages hang off the thread, no member_id of their own).',
        'assembly_attendances' => 'HANDLED here: the attendance register keeps the row + mode/proxy as assembly evidence; the name SNAPSHOT it holds is redacted.',
    ];

    public function handle(Member $member): Member
    {
        // 1. Delete every member-linked FILE from the private disk: photo, ID scan, medical certificate, and
        //    every generated document PDF.
        foreach (['photo_path', 'document_scan_path', 'medical_cert_path'] as $pathField) {
            if (filled($member->{$pathField})) {
                Storage::disk('documents')->delete($member->{$pathField});
            }
        }
        foreach ($member->documents()->get() as $document) {
            if (filled($document->path)) {
                Storage::disk('documents')->delete($document->path);
            }
        }
        // The member's captured POS signatures are biometric-ish PII on retained dispensation records —
        // delete the encrypted file and null the pointer, keeping the dispensation itself (prompt 113).
        foreach ($member->dispensations()->withoutGlobalScopes()->whereNotNull('signature_path')->get() as $dispensation) {
            Storage::disk('documents')->delete((string) $dispensation->signature_path);
            $dispensation->forceFill(['signature_path' => null])->save();
        }

        // 2. Scrub the member row — including the HEALTH flag and the medical-cert path (Art. 9).
        $clearedFields = ['first_name', 'last_name', 'email', 'phone', 'address', 'date_of_birth', 'document_number', 'document_hash', 'is_therapeutic', 'medical_cert_path'];
        $member->forceFill([
            'first_name' => 'ANONIMIZADO',
            'last_name' => 'Socio '.substr($member->id, 0, 8),
            'email' => null,
            'phone' => null,
            'address' => null,
            'date_of_birth' => null,
            'document_number' => null,
            'document_hash' => null,
            'is_therapeutic' => false,
            'photo_path' => null,
            'document_scan_path' => null,
            'medical_cert_path' => null,
            'anonymised_at' => now(),
        ])->save();

        // 3. Generated documents: retain the legal-obligation ones as REDACTED metadata, destroy the rest.
        foreach ($member->documents()->get() as $document) {
            if (in_array($document->type, self::RETAINED_DOCUMENT_TYPES, true)) {
                $snapshot = $document->snapshot ?? [];
                $snapshot['nombre'] = '[borrado]';
                $snapshot['documento'] = '[borrado]';
                $document->update(['snapshot' => $snapshot, 'path' => null]);
            } else {
                $document->delete();
            }
        }

        // 4. Revoke credentials and redact the member's own AUDIT rows (longer-retained than member data).
        $member->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        (new RedactMemberAuditLogs)->handle($member);

        // 5. Convocatoria rolls snapshot the member's name + email. Keep the row (and its NOTIFIED/NO_EMAIL
        //    status) as the assembly's legal evidence that they were convened, but redact the personal data.
        ConvocatoriaRecipient::query()->where('member_id', $member->id)->update(['name' => '[borrado]', 'email' => null]);

        // Message threads keep the member's subject; their own messages hold free-text PII. Keep the threads +
        // timestamps as evidence of contact, redact the subject and scrub the member-authored message bodies
        // (staff replies are the club's own record and stay).
        $threadIds = MessageThread::query()->withoutGlobalScopes()->where('member_id', $member->id)->pluck('id');
        MessageThread::query()->withoutGlobalScopes()->where('member_id', $member->id)->update(['subject' => '[borrado]']);
        Message::query()->whereIn('thread_id', $threadIds)->where('author', MessageAuthor::MEMBER->value)->update(['body' => '[borrado]']);
        // The assembly attendance register snapshots the member's name too. Keep the row (and its present/proxy
        // mode) as evidence they attended a given assembly, but redact the name.
        AssemblyAttendance::query()->withoutGlobalScopes()->where('member_id', $member->id)->update(['name' => '[borrado]']);

        // 6. Record the erasure itself WITHOUT any of the scrubbed values — the fact, not the data.
        (new RecordAuditLog)->handle('member.anonymised', $member, null, ['fields_cleared' => $clearedFields]);

        return $member;
    }
}
