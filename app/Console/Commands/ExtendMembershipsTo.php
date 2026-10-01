<?php

namespace App\Console\Commands;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\RecordAuditLog;
use App\Enums\MembershipStatus;
use App\Models\Location;
use App\Models\Membership;
use Illuminate\Console\Command;

/**
 * Prompt 348 — memberships at every club, after the fact:
 *  - `csc:extend-memberships-to {sede}` — a sede opened later: every member with an active HOME membership elsewhere
 *    gets a linked one there (no fee; it follows the home one);
 *  - `csc:extend-memberships-to --all-sedes` — the one-off backfill for members enrolled before *Alta en todas las
 *    sedes*: each active home membership is linked across to every other active sede (never the store) where the member
 *    has none. Run deliberately, once (SETUP.md); never scheduled.
 * Audited (`membership.enrolled` per linked row, plus one `memberships.extended` summary); prints the count.
 */
class ExtendMembershipsTo extends Command
{
    protected $signature = 'csc:extend-memberships-to {sede? : The new sede (name or id)} {--all-sedes : Link every active home membership across every sede}';

    protected $description = 'Add linked memberships at a new sede (or, with --all-sedes, across every sede) for members with an active home membership';

    public function handle(): int
    {
        $homes = Membership::query()->withoutGlobalScopes()
            ->whereNull('covered_by_id')->where('status', MembershipStatus::ACTIVE)
            ->with('member')->orderBy('starts_at')->get();

        if ($this->option('all-sedes')) {
            $created = $homes->sum(fn (Membership $home): int => EnrolMembership::linkAcross($home));
            (new RecordAuditLog)->handle('memberships.extended', null, null, ['scope' => 'all-sedes', 'created' => $created]);
            $this->info("Membresías vinculadas creadas: {$created}");

            return self::SUCCESS;
        }

        $ref = (string) $this->argument('sede');
        $sede = Location::query()->withoutGlobalScopes()->where(fn ($q) => $q->whereKey($ref)->orWhere('name', $ref))->first();
        if ($sede === null || $sede->isStore() || ! $sede->active) {
            $this->error('Indica una sede activa (no el almacén), por nombre o id, o usa --all-sedes.');

            return self::FAILURE;
        }

        $created = 0;
        foreach ($homes->where('organisation_id', $sede->organisation_id)->unique('member_id') as $home) {
            if ($home->location_id === $sede->id || $home->member->activeMembershipAt($sede) !== null) {
                continue;
            }
            $linked = Membership::create([
                'organisation_id' => $home->organisation_id, 'member_id' => $home->member_id, 'location_id' => $sede->id,
                'tier_id' => $home->tier_id, 'starts_at' => $home->starts_at, 'expires_at' => $home->expires_at,
                'fee_cents' => 0, 'status' => $home->status, 'covered_by_id' => $home->id,
            ]);
            (new RecordAuditLog)->handle('membership.enrolled', $linked, null, ['covered_by' => $home->id, 'fee_cents' => 0]);
            $created++;
        }
        (new RecordAuditLog)->handle('memberships.extended', $sede, null, ['created' => $created]);
        $this->info("Membresías vinculadas creadas en {$sede->name}: {$created}");

        return self::SUCCESS;
    }
}
