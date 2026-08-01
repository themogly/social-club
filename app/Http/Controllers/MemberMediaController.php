<?php

namespace App\Http\Controllers;

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
 * (DispensationPolicy::view — counter/report right + org + location).
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
