<?php

namespace App\Actions\Members;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MrzFieldStat;
use App\Support\DocumentVault;
use App\Support\MrzPrefill;
use App\Support\Settings;
use App\Support\Weight;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;

/**
 * An invited applicant submits their details (code-style audit).
 *
 * This was ~110 lines inside `ApplicationController::store()` — the one place in the codebase where domain
 * logic lived in a controller, and the worst place for it: an UNAUTHENTICATED route that writes Article 9
 * material to the encrypted vault and stamps the consent record the club later relies on. Every comparable
 * write here is an Action with its own tests (`CommitDispensation`, `CommitOrder`, `ApproveApplication`,
 * `RecordFeePayment`); this one was reachable only through HTTP.
 *
 * It writes the application PAYLOAD and nothing else. The age gate, the duplicate search, member creation
 * and consent capture stay in {@see ApproveApplication}, which re-runs every one of them server-side — this
 * never duplicates them. What it DOES capture now, so approval cannot get it wrong later, is the consent
 * text VERSION and the LOCALE the applicant actually read (prompts 97 + 153): `RecordMemberConsent` stamps
 * these at approval, so neither can silently become a later revision they never saw.
 *
 * The controller keeps what is genuinely HTTP: finding the invite, refusing a dead link, the spam guard and
 * the redirect.
 */
class SubmitApplication
{
    /** File-bearing submissions per IP per hour — bounds vault WRITES, not bandwidth. See storageAllowed(). */
    public const UPLOADS_PER_HOUR = 5;

    /**
     * @param  array<string, mixed>  $data  the validated form data (SubmitApplicationRequest)
     * @param  array<string, UploadedFile|null>  $files  ['photo' => …, 'document_scan' => …]; either may be null
     * @param  string  $token  the raw invite token — the key the MRZ prefill was cached under
     * @param  string|null  $ip  the submitter's IP, for the upload rate limit; null skips it
     */
    public function handle(MemberApplication $application, array $data, array $files, string $token, ?string $ip = null): MemberApplication
    {
        $payload = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'],
            'address' => $data['address'] ?? null,
            'document_type' => $data['document_type'],
            'document_number' => $data['document_number'],
            'is_therapeutic' => (bool) ($data['is_therapeutic'] ?? false),
            // Sponsor by name OR number (prompt 97): resolve best-effort, and KEEP the raw text the applicant
            // typed so the reviewer can find them even when it doesn't match a record exactly.
            'avalador_ref' => $data['avalador_ref'] ?? null,
            'avalador_member_id' => $this->resolveAvalador($application, $data['avalador_ref'] ?? null),
            // Empty ("Prefiero no indicarlo") arrives as null (ConvertEmptyStringsToNull) ⇒ no declaration.
            'declared_monthly_cg' => isset($data['declared_monthly_g'])
                ? Weight::fromGrams($data['declared_monthly_g'])->centigrams
                : null,
            'consents' => ['membership', 'data_processing'],
            'consent_version' => (string) Settings::get('consent_text_version', '1.0'),
            'consent_locale' => app()->getLocale(),
        ];

        $photo = $files['photo'] ?? null;
        $scan = $files['document_scan'] ?? null;
        $allowed = $this->storageAllowed($photo !== null || $scan !== null, $ip);

        // Optional identity photo (prompt 157): encrypted onto the PRIVATE disk now — never the public disk,
        // never an unsigned path — with only the path on the payload. ApproveApplication points the new member
        // at it. A rejected or abandoned application is anonymised and this photo deleted by
        // `applications:prune-retention` past application_retention_days.
        if ($allowed && $photo !== null) {
            $payload['photo_path'] = DocumentVault::storeUpload($photo, 'member-photos');
        }

        // Optional identity DOCUMENT (prompt 178 — 155's part B). Same vault, same private encrypted disk, same
        // `member-id-scans` directory the staff MemberForm writes to, so an application's scan and a member's
        // scan are one kind of object with one serving path (signed, access-logged). A DIFFERENT artefact from
        // the photo — a face is not a document — so its own payload key, its own member column, never merged.
        // On approval the member points at THIS SAME FILE rather than a copy.
        if ($allowed && $scan !== null) {
            $payload['document_scan_path'] = DocumentVault::storeUpload($scan, 'member-id-scans');
        }

        // Prompt 179 — the read rate, measured from real use: was each prefilled field corrected? Counts only,
        // recorded BEFORE the prefill is discarded, and discarded here so the next person handed this tablet
        // cannot inherit it.
        $this->recordMrzCorrections($application, $payload, $token);

        // Still PENDING — it now carries the applicant's details and enters the review queue.
        $application->update(['payload' => $payload, 'submitted_at' => now()]);

        return $application;
    }

    /**
     * May this submission's files reach the disk?
     *
     * The uploads are NOT a separate endpoint — they ride the same POST, so the route's `throttle:10,1` and
     * ApplicationSpamGuard already apply to them unchanged. What that does not bound is STORAGE: ten
     * submissions a minute, each up to the shared 12 MB ceiling, is ~120 MB/min of encrypted vault writes per
     * IP, sustained, on an unauthenticated route. So file-bearing submissions get a tighter limit on top.
     *
     * Honest about its scope: the bytes have already crossed the wire and been parsed by PHP before this runs,
     * so this bounds what reaches the DISK, not bandwidth — bandwidth is nginx's `client_max_body_size` and
     * PHP's `upload_max_filesize` (prompt 164 reconciled those three numbers).
     *
     * Five an hour is far above a genuine applicant (who uploads once, twice if they mis-picked a file) and far
     * below anything worth doing to a disk. Over the limit the application still SUBMITS — the files are simply
     * not stored, because an upload is optional and losing it must never cost someone their application.
     */
    private function storageAllowed(bool $filesPresent, ?string $ip): bool
    {
        if (! $filesPresent || $ip === null) {
            return true;
        }

        return RateLimiter::attempt('application-upload:'.$ip, self::UPLOADS_PER_HOUR, fn () => true, 3600);
    }

    /**
     * Count, per field, how often a prefilled value was kept and how often it was corrected — which IS the
     * read rate, gathered on real documents with no corpus to assemble, hold or destroy.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordMrzCorrections(MemberApplication $application, array $payload, string $token): void
    {
        foreach (MrzPrefill::get($token) as $field => $read) {
            $submitted = $payload[$field] ?? null;

            MrzFieldStat::record(
                $application->organisation_id,
                $field,
                corrected: is_string($submitted) && trim($submitted) !== trim($read),
            );
        }

        MrzPrefill::forget($token);
    }

    /**
     * Best-effort: match a sponsor by member NUMBER or by NAME within the invite's organisation (prompt 97 —
     * a prospect usually knows the name, not the number). An ambiguous name match is left unresolved for the
     * reviewer (the raw text is kept on the payload) rather than guessing the wrong socio.
     */
    private function resolveAvalador(MemberApplication $application, ?string $ref): ?string
    {
        $ref = trim((string) $ref);

        if ($ref === '') {
            return null;
        }

        $base = fn () => Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $application->organisation_id)
            ->where('status', MemberStatus::ACTIVE->value);

        $byNumber = $base()->where('member_no', $ref)->value('id');

        if ($byNumber !== null) {
            return $byNumber;
        }

        // A NAME match, only when it is unambiguous (exactly one active socio). Matched in PHP so the
        // full-name comparison is portable across SQLite (dev) and MySQL (prod).
        $needle = mb_strtolower($ref);
        $byName = $base()->get(['id', 'first_name', 'last_name'])
            ->filter(fn (Member $m): bool => mb_strtolower(trim($m->first_name.' '.$m->last_name)) === $needle)
            ->pluck('id');

        return $byName->count() === 1 ? (string) $byName->first() : null;
    }
}
