<?php

namespace App\Http\Controllers\Member;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Base for the member-PWA feature controllers (avisos, eventos, notificaciones).
 *
 * EVERYTHING is scoped to the authenticated socio on the `member` guard — there is
 * NO member id in any URL, so one member can never reach another's data. Mirrors the
 * scoping contract established by App\Http\Controllers\Socio\PwaController (auth spine).
 */
abstract class MemberController extends Controller
{
    protected function member(): Member
    {
        /** @var Member $member */
        $member = Auth::guard('member')->user();

        return $member;
    }

    /**
     * The location ids the member currently belongs to (active memberships). Used to
     * scope location-targeted announcements/events; an all-locations item (location_id
     * null) is visible regardless.
     *
     * @return Collection<int, string>
     */
    protected function memberLocationIds(Member $member): Collection
    {
        return $member->memberships()->withoutGlobalScopes()
            ->where('status', MembershipStatus::ACTIVE->value)
            ->pluck('location_id')
            ->filter()
            ->values();
    }
}
