<?php

namespace Tests\Feature\Audit;

use PDO;
use Tests\TestCase;

/**
 * Prompt 114 — the integrity harness is the acceptance gate for the 103–113 batch. It is a standalone tool
 * that reads real data, so it is exercised against a COPY of the seeded dev database (never the in-memory
 * test DB, and never the real one). These prove the property that makes it a gate: it exits non-zero when a
 * system invariant is broken, and its mutating sections roll back so a run leaves the data unchanged.
 */
class IntegrityHarnessTest extends TestCase
{
    private function seededDb(): ?string
    {
        $path = database_path('database.sqlite');

        // A seeded file is substantial; an empty/migrated-only one is not — skip rather than false-fail in CI.
        return (is_file($path) && filesize($path) > 50000) ? $path : null;
    }

    private function copyOfSeed(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'harness_').'.sqlite';
        copy((string) $this->seededDb(), $tmp);

        return $tmp;
    }

    /** @return array{int, string} exit code + combined output */
    private function runHarness(string $db, string $args = ''): array
    {
        $cmd = 'DB_CONNECTION=sqlite DB_DATABASE='.escapeshellarg($db).' php '
            .escapeshellarg(base_path('audits/integrity-harness.php')).' '.$args.' 2>&1';
        exec($cmd, $out, $code);

        return [$code, implode("\n", $out)];
    }

    /** @return array<string, int> */
    private function counts(string $db): array
    {
        $pdo = new PDO('sqlite:'.$db);
        $out = [];
        foreach (['batches', 'expenses', 'dispensations', 'stock_movements'] as $t) {
            $out[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        }

        return $out;
    }

    public function test_the_harness_loads_and_lists_its_sections(): void
    {
        if ($this->seededDb() === null) {
            $this->markTestSkipped('No seeded dev database — run `php artisan migrate:fresh --seed`.');
        }
        $db = $this->copyOfSeed();
        [$code, $out] = $this->runHarness($db, '--list');
        @unlink($db);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('ledger', $out);
    }

    public function test_a_broken_invariant_makes_the_harness_exit_nonzero(): void
    {
        if ($this->seededDb() === null) {
            $this->markTestSkipped('No seeded dev database.');
        }
        $db = $this->copyOfSeed();

        // Break one batch's remaining_cg directly — the movement ledger no longer sums to the stock.
        (new PDO('sqlite:'.$db))->exec(
            'UPDATE batches SET remaining_cg = remaining_cg + 9999 '
            .'WHERE id = (SELECT id FROM batches WHERE remaining_cg IS NOT NULL LIMIT 1)'
        );

        [$code] = $this->runHarness($db, 'ledger');
        @unlink($db);

        $this->assertNotSame(0, $code, 'A broken ledger invariant must fail the harness — that is what makes it a gate.');
    }

    public function test_running_the_harness_does_not_change_the_data(): void
    {
        if ($this->seededDb() === null) {
            $this->markTestSkipped('No seeded dev database.');
        }
        $db = $this->copyOfSeed();

        $before = $this->counts($db);
        $this->runHarness($db); // the dashboard/till sections mutate, then roll back
        $after = $this->counts($db);
        @unlink($db);

        $this->assertSame($before, $after, 'The mutating sections must roll back — a run leaves the data unchanged.');
    }
}
