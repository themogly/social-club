<?php

namespace App\Http\Controllers\Member;

use App\Models\Announcement;
use App\Models\Member;
use App\Models\Scopes\OrganisationScope;
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
        // Escape ONLY the organisation scope (the member guard does not set the active scope), NOT the
        // soft-delete scope — a deleted announcement must never reach a member (prompt 95 sweep).
        $announcements = Announcement::query()->withoutGlobalScope(OrganisationScope::class)
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
