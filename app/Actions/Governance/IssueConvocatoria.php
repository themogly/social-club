<?php

namespace App\Actions\Governance;

use App\Actions\RecordAuditLog;
use App\Enums\ConvocatoriaRecipientStatus;
use App\Mail\ConvocatoriaMail;
use App\Models\Convocatoria;
use App\Models\ConvocatoriaRecipient;
use App\Models\Member;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Issue a convocatoria: freeze the recipient roll and send the notice (prompt 88). This is the one writer
 * that sets issued_at, and it does three inseparable things:
 *
 *  1. Enforces the notice period — the assembly may not be held sooner than the configured minimum number of
 *     days after the notice goes out (a legal requirement; too-short notice invalidates the assembly).
 *  2. FREEZES the roll — every member of the association as-at the notice date is snapshotted into
 *     convocatoria_recipients (number, name, email) and NEVER recomputed. Members with no email are recorded
 *     as NO_EMAIL, un-notified — visible, so the club can reach them another way, never silently dropped.
 *  3. Sends ONE email per member — a separate queued message each (Mail::to()->queue()), never a shared
 *     To/CC that would leak the whole membership's addresses to every recipient.
 *
 * A general assembly is of the ASSOCIATION: the roll is always the whole org as-at the notice date. The
 * convocatoria's location is only the venue where it is held, never a filter on who is convened.
 */
class IssueConvocatoria
{
    public function handle(Convocatoria $convocatoria, User $actor): Convocatoria
    {
        if (! $actor->can('minutes.manage')) {
            throw new AuthorizationException('Convening an assembly requires the minutes.manage permission.');
        }

        if ($convocatoria->isIssued()) {
            throw new RuntimeException('This convocatoria has already been issued.');
        }

        $noticeDays = (int) Settings::get('assembly_notice_days', 15);
        if ($convocatoria->held_at->lt(now()->addDays($noticeDays))) {
            throw new RuntimeException("The assembly must be at least {$noticeDays} days after the notice is issued.");
        }

        $members = $this->rollAsAtNow($convocatoria);
        $fractionBp = (int) Settings::get('minute_quorum_fraction_bp', 5000);

        $recipients = DB::transaction(function () use ($convocatoria, $actor, $members, $noticeDays, $fractionBp): array {
            $rows = [];
            foreach ($members as $member) {
                $email = trim((string) $member->email);
                $rows[] = ConvocatoriaRecipient::create([
                    'convocatoria_id' => $convocatoria->id,
                    'member_id' => $member->id,
                    'member_no' => (string) $member->member_no,
                    'name' => $member->fullName(),
                    'email' => $email !== '' ? $email : null,
                    'status' => $email !== '' ? ConvocatoriaRecipientStatus::NOTIFIED : ConvocatoriaRecipientStatus::NO_EMAIL,
                    'notified_at' => $email !== '' ? now() : null,
                ]);
            }

            $convocatoria->update([
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'notice_days' => $noticeDays,
                'roll_count' => count($rows),
                'quorum_required' => (int) ceil(count($rows) * $fractionBp / 10000),
            ]);

            return $rows;
        });

        // Queue AFTER commit — one separate message per member, never a shared To/CC. A member without an
        // email is left un-notified (already recorded NO_EMAIL) rather than silently skipped.
        $notified = 0;
        foreach ($recipients as $recipient) {
            if ($recipient->email === null) {
                continue;
            }
            Mail::to($recipient->email)->queue(ConvocatoriaMail::fromConvocatoria($convocatoria, $recipient->name));
            $notified++;
        }

        (new RecordAuditLog)->handle('convocatoria.issued', $convocatoria, null, [
            'roll_count' => count($recipients),
            'notified' => $notified,
            'no_email' => count($recipients) - $notified,
            'notice_days' => $noticeDays,
            'quorum_required' => $convocatoria->fresh()?->quorum_required,
            'held_at' => $convocatoria->held_at->toIso8601String(),
        ]);

        return $convocatoria->refresh();
    }

    /**
     * Every member of the association as-at now (the notice date): joined by now and not yet left. This is
     * the same "as-at" semantics the acta quorum uses, so the convened roll and the quorum agree.
     *
     * @return Collection<int, Member>
     */
    private function rollAsAtNow(Convocatoria $convocatoria): Collection
    {
        $asAt = now();

        return Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $convocatoria->organisation_id)
            ->whereNotNull('joined_at')
            ->where('joined_at', '<=', $asAt)
            ->where(fn ($q) => $q->whereNull('left_at')->orWhere('left_at', '>', $asAt))
            ->orderBy('member_no')
            ->get();
    }
}
