<?php

namespace App\Http\Controllers\Counter;

use App\Actions\Members\CaptureMemberPhoto;
use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Receive a member's identity photo captured at the counter (prompt 157) — from the live camera (a JPEG the
 * Alpine `photoCapture` component packages from a canvas frame) OR the upload fallback (a plain file input,
 * for a tablet with a broken camera or a club that photographs people another way). Both POST the same `photo`
 * file here.
 *
 * Authorisation is object-level (`capturePhoto` policy): a counter operator on the member's OWN organisation.
 * The Member route binding already resolves only within the active org (global scope), so a cross-org id 404s
 * before the policy even runs; the policy is the second wall. Encryption to the private disk, prior-file
 * cleanup and the audit row are the CaptureMemberPhoto action's job — this controller only guards + validates.
 */
class MemberPhotoController extends Controller
{
    public function store(Request $request, Member $member, CaptureMemberPhoto $capture): JsonResponse
    {
        Gate::authorize('capturePhoto', $member);

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
            'source' => ['nullable', 'in:counter,door'],
        ]);

        $capture->handle($member, $validated['photo'], $request->user(), $validated['source'] ?? 'counter');

        return response()->json(['ok' => true]);
    }
}
