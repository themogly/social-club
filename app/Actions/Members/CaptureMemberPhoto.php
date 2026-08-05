<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\Member;
use App\Models\User;
use App\Support\DocumentVault;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The ONE writer for a member's identity photo (prompt 157). Encrypts the image onto the private `documents`
 * disk via DocumentVault — never the public disk, never an unsigned path — swaps it onto `member.photo_path`
 * (which the check-in screen and the POS already render through a short-lived signed, access-logged URL),
 * deletes any prior photo file so a replaced photo does not linger on the disk, and writes an audit row. The
 * counter camera capture, the counter upload fallback and the application-approval path all go through here;
 * the admin MemberForm writes the same column through the same DocumentVault directory (`member-photos`), so
 * retention + AnonymiseMember cover every photo identically regardless of who captured it.
 *
 * Framing (prompt 157): this photo is compared by a HUMAN at the counter — identity verification, not the
 * technical biometric processing that Article 9 turns on. It must NEVER become automated face matching; that
 * is a separate decision with its own lawful basis, RAT entry and almost certainly a DPIA (see DECISIONS.md).
 */
class CaptureMemberPhoto
{
    public function handle(Member $member, UploadedFile $file, ?User $actor = null, string $source = 'counter'): Member
    {
        return DB::transaction(function () use ($member, $file, $actor, $source): Member {
            $previous = $member->photo_path;

            $path = DocumentVault::storeUpload($file, 'member-photos');

            $member->forceFill(['photo_path' => $path])->save();

            // Replacing a photo must not leave the old ciphertext on the disk (or in a snapshot/backup path).
            if (filled($previous) && $previous !== $path) {
                Storage::disk('documents')->delete($previous);
            }

            (new RecordAuditLog)->handle('member.photo.captured', $member, null, [
                'source' => $source,
                'captured_by' => $actor?->id,
            ]);

            return $member;
        });
    }
}
