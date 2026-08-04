<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prompt 146 — backfill: normalise every existing users.email and members.email to lowercase + trim, so the
 * stored data matches what the model now writes and lookups now expect. Application-side normalisation, not a
 * collation side effect.
 *
 * ONE-WAY by nature: the original casing is not recoverable, so down() cannot restore it. Every other
 * migration in this project is reversible (verified); this is the deliberate, documented exception.
 *
 * COLLISION SAFETY: `users.email` is unique. Lowercasing could turn `Ben@club.es` and `ben@club.es` into two
 * rows that violate that index. This detects any such case-only pair FIRST — in PHP, so it is identical on
 * SQLite and MySQL regardless of collation — and aborts with a clear report of which addresses clash, rather
 * than throwing a constraint violation halfway through and leaving the table half-converted. `members.email`
 * is NOT unique (a couple or a carer may legitimately share one address — see DECISIONS), so it needs no such
 * guard; its duplicates are surfaced by FindDuplicateMembers, not refused by the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardUsersAgainstCaseCollisions();

        $this->normalise('users');
        $this->normalise('members');
    }

    public function down(): void
    {
        // Deliberately irreversible — the original email casing was not preserved (prompt 146).
    }

    /**
     * Refuse to run if lowercasing would make two `users` rows collide on the unique email index. Detected in
     * PHP (case-sensitive comparison) so the behaviour is the same on every driver, before any write.
     */
    private function guardUsersAgainstCaseCollisions(): void
    {
        $byNormalised = [];
        foreach (DB::table('users')->whereNotNull('email')->get(['id', 'email']) as $row) {
            $normalised = Str::lower(trim((string) $row->email));
            if ($normalised !== '') {
                $byNormalised[$normalised][] = (string) $row->email;
            }
        }

        $collisions = array_filter($byNormalised, fn (array $variants): bool => count($variants) > 1);

        if ($collisions !== []) {
            $report = collect($collisions)
                ->map(fn (array $variants, string $normalised): string => $normalised.' ← '.implode(', ', $variants))
                ->implode('; ');

            throw new RuntimeException(
                'Cannot normalise users.email: lowercasing would create duplicate accounts that violate the '
                .'unique index. Resolve these case-only duplicates first, then re-run the migration: '.$report
            );
        }
    }

    /** Lowercase + trim every stored email that is not already normalised. Only changed rows are written. */
    private function normalise(string $table): void
    {
        DB::table($table)->whereNotNull('email')->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                $normalised = Str::lower(trim((string) $row->email));
                if ($normalised !== (string) $row->email) {
                    DB::table($table)->where('id', $row->id)->update(['email' => $normalised === '' ? null : $normalised]);
                }
            }
        });
    }
};
