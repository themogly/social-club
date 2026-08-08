<?php

namespace App\Http\Controllers;

use App\Models\ConsentRecord;
use App\Models\Dispensation;
use App\Models\Member;
use App\Support\VaultStream;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Streams a member PHOTO or a POS SIGNATURE from the encrypted private disk (prompt 113) — the two Article-9
 * files that prompt 32 scoped out and that used to be served by a bare `temporaryUrl` (no user binding, no
 * policy, no access log). Both reuse VaultStream's five protections; only the authorisation differs: a photo
 * is identity data seen at the counter (members.view + org), a signature belongs to a dispensation
 * (DispensationPolicy::view — counter/report right + org + location), and a CONSENT signature (prompt 220)
 * belongs to the member whose consent it is.
 */
class MemberMediaController extends Controller
{
    public function photo(Request $request, Member $member): Response
    {
        return VaultStream::respond(
            $request,
            (string) $member->photo_path,
            fn () => Gate::authorize('viewPhoto', $member),
            ['subject_type' => $member->getMorphClass(), 'subject_id' => $member->id],
        );
    }

    /**
     * The signature drawn over a CONSENT text at sign-up (prompt 220).
     *
     * Authorised on the MEMBER, not the consent row: a consent record has no independent audience — whoever
     * may view the member's record may see what they signed, and nobody else. Through its own org-scoped
     * ability, not plain `view`, because a route-model-bound endpoint gets no global scope to lean on. Same five VaultStream
     * protections, and the access log names the consent so a view of a signature is distinguishable from a
     * view of the member.
     */
    public function consentSignature(Request $request, ConsentRecord $consent): Response
    {
        return VaultStream::respond(
            $request,
            (string) $consent->signature_path,
            fn () => Gate::authorize('viewConsentSignature', $consent->member),
            ['subject_type' => $consent->getMorphClass(), 'subject_id' => $consent->id],
        );
    }

    public function signature(Request $request, Dispensation $dispensation): Response
    {
        return VaultStream::respond(
            $request,
            (string) $dispensation->signature_path,
            fn () => Gate::authorize('view', $dispensation),
            ['subject_type' => $dispensation->getMorphClass(), 'subject_id' => $dispensation->id],
        );
    }
}
