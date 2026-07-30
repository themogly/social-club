<?php

namespace App\Http\Controllers\Member;

use App\Models\Announcement;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;

/** The socio's announcements feed — PUBLISHED, in-scope, non-expired only. */
class AnnouncementController extends MemberController
{
    public function index(): View
    {
        $member = $this->member();

        return view('socio.announcements', [
            'announcements' => $this->visibleTo($member),
        ]);
    }

    /**
     * @return Collection<int, Announcement>
     */
    private function visibleTo(Member $member): Collection
    {
        $locationIds = $this->memberLocationIds($member)->all();

        /** @var Collection<int, Announcement> $announcements */
        $announcements = Announcement::query()->withoutGlobalScopes()
            ->where('organisation_id', $member->organisation_id)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $q): Builder => $q->whereNull('location_id')->orWhereIn('location_id', $locationIds))
            ->latest('published_at')
            ->limit(50)
            ->get();

        return $announcements;
    }
}
