<?php

namespace App\Http\Controllers\Counter;

use App\Actions\Members\CaptureMemberPhoto;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Support\CounterOperator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Receive a member's identity photo captured at the counter (prompt 157) — from the live camera (a JPEG the
 * Alpine `photoCapture` component packages from a canvas frame) OR the upload fallback (a plain file input,
 * for a tablet with a broken camera or a club that photographs people another way). Both POST the same `photo`
 * file here.
 *
 * Authorisation is object-level (`capturePhoto` policy), asked of the PIN OPERATOR (prompt 261): an identified
 * counter operator on the member's OWN organisation.
 * The Member route binding already resolves only within the active org (global scope), so a cross-org id 404s
 * before the policy even runs; the policy is the second wall. Encryption to the private disk, prior-file
 * cleanup and the audit row are the CaptureMemberPhoto action's job — this controller only guards + validates.
 */
class MemberPhotoController extends Controller
{
    public function store(Request $request, Member $member, CaptureMemberPhoto $capture): JsonResponse
    {
        // Prompt 261 — a PIN-identified operator is required, like every counter write since 255. This route is a
        // plain controller, so 255's `requireOperator()` sweep of the Livewire screens never reached it: with
        // nobody at the PIN, anyone at an unattended or locked tablet could replace a member's identity photo —
        // the face staff verify against (157) — with their own, and later be served as that member.
        $operator = CounterOperator::current();

        if ($operator === null) {
            return response()->json(['message' => __('Identifícate con tu PIN antes de continuar.')], 403);
        }

        // Asked of the OPERATOR, not the tablet's login (255's rule).
        Gate::forUser($operator)->authorize('capturePhoto', $member);

        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
            'source' => ['nullable', 'in:counter,door'],
        ]);

        $capture->handle($member, $validated['photo'], $operator, $validated['source'] ?? 'counter');

        return response()->json(['ok' => true]);
    }
}
