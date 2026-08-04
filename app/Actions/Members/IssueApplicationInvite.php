<?php

namespace App\Actions\Members;

use App\Enums\ApplicationStatus;
use App\Models\MemberApplication;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Create a tokenised member-invitation as ONE atomic thing (prompt 149). Generating the invitation and
 * sending its email are now separate concerns: this writer only creates the row, and mail is queued
 * best-effort by the caller — so a mail failure can never orphan a PENDING invitation nor hide its link.
 *
 * The 48-char token is hashed for lookup and stored encrypted at rest so the link stays re-copyable; expiry
 * comes from invite_expiry_days. Every invitation is attributable: the EMAIL path carries the applicant's
 * (normalised) email; the HAND-OVER path a required reference — a name, or the referring member — so an
 * anonymous live token to join the register cannot exist.
 */
class IssueApplicationInvite
{
    public function handle(User $actor, ?string $locationId, ?string $email, ?string $reference): MemberApplication
    {
        if (! $actor->can('members.create')) {
            throw new AuthorizationException('Generating an invitation requires the members.create permission.');
        }

        $email = filled($email) ? $email : null;
        $reference = filled($reference) ? trim((string) $reference) : null;

        // One of the two identifiers is required — an invitation must be attributable to someone.
        if ($email === null && $reference === null) {
            throw new RuntimeException('An invitation needs either an email or a reference to whoever it is for.');
        }

        $token = Str::random(48);

        return MemberApplication::create([
            'location_id' => $locationId,
            'invite_token_hash' => hash('sha256', $token),
            'invite_token' => $token,                  // encrypted at rest → re-copyable / re-sendable
            'invited_by' => $actor->id,
            'applicant_email' => $email,               // lowercased by the model cast (prompt 146)
            'applicant_reference' => $reference,
            'invite_expires_at' => now()->addDays((int) Settings::get('invite_expiry_days', 14)),
            'payload' => [],
            'status' => ApplicationStatus::PENDING,
        ]);
    }
}
