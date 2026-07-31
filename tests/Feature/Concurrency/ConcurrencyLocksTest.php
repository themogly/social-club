<?php

namespace Tests\Feature\Concurrency;

use Tests\TestCase;

/**
 * Prompt 77 — the three money paths (RecordWalletTransaction, CloseTill, MemberNumber::next) now serialise
 * their read-then-write with a `lockForUpdate` on a contended row, matching CommitDispensation's proven shape.
 *
 * These bugs manifest ONLY under genuine OS-level parallelism against MySQL (REPEATABLE READ) — the way the
 * report reproduced them (parallel processes against a populated DB). Single-process PHPUnit cannot reproduce
 * them: on SQLite `lockForUpdate` is a no-op and transactions serialise anyway; on MySQL a single process runs
 * its "concurrent" transactions one after another. So — per the prompt's explicit allowance — these are SKIPPED
 * with a stated reason rather than silently passing. The fix is verified instead by:
 *   - reading it against CommitDispensation's already-correct lock (same row, same transaction boundary),
 *   - the full sequential suite proving no single-writer behaviour changed, and
 *   - MemberNumberSequenceTest, which deterministically proves numbers are never reissued.
 *
 * To actually exercise the locks, run the scenarios below from an external harness: N forked workers each
 * opening a transaction against the CI MySQL (phpunit.mysql.xml), then assert the post-conditions.
 */
class ConcurrencyLocksTest extends TestCase
{
    private function requiresParallelMysql(): void
    {
        $this->markTestSkipped(
            'Requires genuine parallel processes against MySQL; single-process PHPUnit cannot reproduce a '
            .'lock race. The lockForUpdate fix is verified by reading, the sequential suite, and '
            .'MemberNumberSequenceTest — see the class docblock.'
        );
    }

    /** With debt disabled and €10, N concurrent €8 debits must spend at most the balance; no wrong balance_after. */
    public function test_concurrent_wallet_debits_cannot_bypass_the_debt_limit(): void
    {
        $this->requiresParallelMysql();
    }

    /** A cash movement committed concurrently with a close is included in expected, or the close fails cleanly. */
    public function test_a_cash_movement_racing_a_close_is_counted_or_refused_never_dropped(): void
    {
        $this->requiresParallelMysql();
    }

    /** N concurrent enrolments all succeed with distinct member numbers and no 500. */
    public function test_concurrent_enrolments_get_distinct_member_numbers(): void
    {
        $this->requiresParallelMysql();
    }
}
