<?php

namespace App\Http\Controllers\Member;

use App\Models\Member;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Member-controlled notifications: the Web Push subscription (browser <-> server) and
 * the per-channel opt-outs. The VAPID PUBLIC key is exposed to the client (it must be,
 * to create a subscription); the PRIVATE key is server-only (config/webpush.php) and
 * never leaves the server.
 */
class NotificationController extends MemberController
{
    public function edit(): View
    {
        $member = $this->member();

        return view('socio.notifications', [
            'member' => $member,
            'channels' => Member::PUSH_CHANNELS,
            'optOuts' => $member->pushOptOuts(),
            'vapidPublicKey' => (string) config('webpush.vapid.public_key', ''),
            'subscriptionCount' => $member->pushSubscriptions()->count(),
        ]);
    }

    /** Store (or refresh) a browser push subscription for THIS member only. */
    public function subscribe(Request $request): JsonResponse
    {
        $member = $this->member();

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
            'keys.p256dh' => ['nullable', 'string', 'max:255'],
            'keys.auth' => ['nullable', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:64'],
        ]);

        // Endpoint is globally unique: reassign it to this member if it moved devices/accounts.
        PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'subscribable_type' => $member->getMorphClass(),
                'subscribable_id' => $member->getKey(),
                'public_key' => $data['keys']['p256dh'] ?? null,
                'auth_token' => $data['keys']['auth'] ?? null,
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            ],
        );

        return response()->json(['ok' => true]);
    }

    /** Remove a browser push subscription owned by this member. */
    public function unsubscribe(Request $request): JsonResponse
    {
        $member = $this->member();

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
        ]);

        $member->pushSubscriptions()->where('endpoint', $data['endpoint'])->delete();

        return response()->json(['ok' => true]);
    }

    /** Save the per-channel opt-outs (checkboxes = channels the member wants ON). */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $member = $this->member();

        $data = $request->validate([
            'channels' => ['array'],
            'channels.*' => ['string', 'in:'.implode(',', Member::PUSH_CHANNELS)],
        ]);

        $on = $data['channels'] ?? [];
        $optOuts = array_values(array_diff(Member::PUSH_CHANNELS, $on));

        $member->push_opt_outs = $optOuts;
        $member->save();

        return back()->with('status', __('Tus preferencias de avisos se han guardado.'));
    }
}
