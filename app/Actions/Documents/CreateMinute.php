<?php

namespace App\Actions\Documents;

use App\Enums\MinuteBook;
use App\Models\Member;
use App\Models\Minute;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Open a new acta in a book. Numbering is SEQUENTIAL per (organisation, book) with
 * no gaps: the next number is taken under a row lock inside a transaction, and the
 * unique(organisation, book, number) constraint is the backstop under true
 * concurrency (a losing racer retries). Quorum is computed against the members who
 * were active AT the meeting date, never today's roll. Created UNSIGNED — signing
 * (SignMinute) closes it and makes it immutable; a correction is a new minute
 * pointing back via supersedes_id.
 *
 * @phpstan-type MinuteData array{type?: ?string, held_on?: ?string, location_id?: ?string, agenda?: array<int, mixed>, resolutions?: array<int, mixed>, attendees?: list<string>, body?: ?string, supersedes_id?: ?string}
 */
class CreateMinute
{
    /**
     * @param  MinuteData  $data
     */
    public function handle(Organisation $organisation, MinuteBook $book, array $data, User $actor): Minute
    {
        if (! $actor->can('minutes.manage')) {
            throw new AuthorizationException('Managing minutes requires the minutes.manage permission.');
        }

        $heldOn = $data['held_on'] ?? now()->toDateString();
        $attendees = $data['attendees'] ?? [];
        $quorumRequired = $this->quorumRequired($organisation, $heldOn);

        // Retry once on a unique-constraint race (two racers picked the same number).
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($organisation, $book, $data, $heldOn, $attendees, $quorumRequired): Minute {
                    $next = (int) (Minute::withoutGlobalScopes()
                        ->where('organisation_id', $organisation->id)
                        ->where('book', $book->value)
                        ->lockForUpdate()->max('number') ?? 0) + 1;

                    return Minute::create([
                        'organisation_id' => $organisation->id,
                        'book' => $book,
                        'number' => $next,
                        'type' => $data['type'] ?? null,
                        'held_on' => $heldOn,
                        'location_id' => $data['location_id'] ?? null,
                        'agenda' => $data['agenda'] ?? [],
                        'resolutions' => $data['resolutions'] ?? [],
                        'attendees' => $attendees,
                        'quorum_present' => count($attendees),
                        'quorum_required' => $quorumRequired,
                        'body' => $data['body'] ?? null,
                        'supersedes_id' => $data['supersedes_id'] ?? null,
                    ]);
                });
            } catch (QueryException $e) {
                if ($attempt >= 3 || ! str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw $e;
                }
            }
        }
    }

    /** Members active AT the meeting date × the configured quorum fraction, rounded up. */
    private function quorumRequired(Organisation $organisation, string $heldOn): int
    {
        $active = Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)
            ->whereDate('joined_at', '<=', $heldOn)
            ->where(fn ($q) => $q->whereNull('left_at')->orWhereDate('left_at', '>', $heldOn))
            ->count();

        $fractionBp = (int) Settings::get('minute_quorum_fraction_bp', 5000);

        return (int) ceil($active * $fractionBp / 10000);
    }
}
