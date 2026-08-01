<?php

namespace Tests\Feature\Cleanup;

use App\Support\Permissions;
use ReflectionClass;
use Tests\TestCase;

/**
 * The "built it, never wired it" guard (prompt 73). The single most repeated defect in this codebase is not
 * a bug in a feature — it is a finished, tested, permissioned class with nothing that CALLS it
 * (RecordFeePayment, CommitStockTake, RefundDispensation, UpdateDeclaredForecast, GeneticPrice, …). Every one
 * passed `composer check`: the suite proves each unit works; nothing proved anything REACHES it. This closes
 * that hole, mirroring FormCompletenessTest (a guard + an honesty test that fails on a stale allowlist entry).
 *
 * Detection (the part that is easy to get wrong): COMMENTS NEVER COUNT — two hand-run scans during the
 * original investigation produced false negatives by counting docblock mentions as callers. So every target
 * file is stripped of T_COMMENT / T_DOC_COMMENT before matching, and `tests/` never counts as usage (a class
 * exercised only by its own unit test is exactly the thing being caught).
 *
 * Reachability is DIRECT, not transitive: "referenced from a non-test file under app/, routes/ or
 * database/seeders/". Transitive reachability-from-an-entry-point is stronger but far more brittle; the direct
 * check has caught every real instance to date. Recorded as a deliberate limit in DECISIONS.
 */
class UnreachableCodeGuardTest extends TestCase
{
    /**
     * Actions deliberately staged ahead of their caller. Each needs a written reason a reviewer will see, and
     * the honesty test fails when an entry is no longer needed. Empty today — every offender was wired.
     *
     * @var array<string, string>
     */
    private const ACTION_ALLOWLIST = [];

    /**
     * Notifications with no dispatch site yet. Empty today.
     *
     * @var array<string, string>
     */
    private const NOTIFICATION_ALLOWLIST = [];

    /**
     * Permissions declared in Permissions::ALL and assigned to roles but not yet CHECKED anywhere — the same
     * dead-code shape, for permissions. Each is real (verified: no `can()`/gate/policy references them), and
     * flagged here for wiring-or-removal rather than silently carried.
     *
     * @var array<string, string>
     */
    private const PERMISSION_ALLOWLIST = [
        // member.limits.set + cash.bank were WIRED in prompt 81 (SetMemberLimits + MemberPolicy::setLimits;
        // the till's BANKED movement gated on cash.bank) — removed from the allowlist, now enforced as checked.
        'members.transfer' => 'declared + role-assigned; the cross-location member transfer UI is not built yet — wire or remove.',
        'stock.transfer' => 'declared + role-assigned; the inter-location stock transfer UI is not built yet — wire or remove.',
    ];

    // --- The guards ------------------------------------------------------------

    public function test_every_action_class_is_reached_from_a_non_test_caller(): void
    {
        $unreached = [];
        foreach ($this->classesIn('app/Actions') as $fqcn => $meta) {
            if (array_key_exists($fqcn, self::ACTION_ALLOWLIST)) {
                continue;
            }
            if (! $this->isReferenced($fqcn, $meta['short'], $meta['file'])) {
                $unreached[] = $fqcn;
            }
        }

        $this->assertSame([], $unreached, 'Unreachable Action class(es) — finished but never called: '.implode(', ', $unreached));
    }

    public function test_the_action_allowlist_stays_honest(): void
    {
        $classes = $this->classesIn('app/Actions');
        $stale = [];
        foreach (self::ACTION_ALLOWLIST as $fqcn => $reason) {
            $meta = $classes[$fqcn] ?? null;
            if ($meta === null || $this->isReferenced($fqcn, $meta['short'], $meta['file'])) {
                $stale[] = $fqcn; // reachable (or gone) → the allowlist entry is no longer needed
            }
        }

        $this->assertSame([], $stale, 'Stale ACTION_ALLOWLIST entr(ies) — now reachable, remove them: '.implode(', ', $stale));
    }

    public function test_every_notification_is_reached_from_a_dispatch_site(): void
    {
        $unreached = [];
        foreach ($this->classesIn('app/Notifications') as $fqcn => $meta) {
            if (array_key_exists($fqcn, self::NOTIFICATION_ALLOWLIST)) {
                continue;
            }
            if (! $this->isReferenced($fqcn, $meta['short'], $meta['file'])) {
                $unreached[] = $fqcn;
            }
        }

        $this->assertSame([], $unreached, 'Notification(s) with no dispatch site: '.implode(', ', $unreached));
    }

    public function test_every_declared_permission_is_checked_somewhere(): void
    {
        $unchecked = [];
        foreach ($this->declaredPermissions() as $permission) {
            if (array_key_exists($permission, self::PERMISSION_ALLOWLIST)) {
                continue;
            }
            if (! $this->permissionIsChecked($permission)) {
                $unchecked[] = $permission;
            }
        }

        $this->assertSame([], $unchecked, 'Permission(s) declared + assigned but never checked: '.implode(', ', $unchecked));
    }

    public function test_the_permission_allowlist_stays_honest(): void
    {
        $stale = [];
        foreach (array_keys(self::PERMISSION_ALLOWLIST) as $permission) {
            if ($this->permissionIsChecked($permission)) {
                $stale[] = $permission; // now checked → remove the allowlist entry
            }
        }

        $this->assertSame([], $stale, 'Stale PERMISSION_ALLOWLIST entr(ies) — now checked, remove them: '.implode(', ', $stale));
    }

    // --- Proof that the guard actually guards ----------------------------------

    public function test_the_detector_reports_a_class_that_is_referenced_nowhere(): void
    {
        // A fabricated action that appears in no file — the detector MUST call it unreachable (else the guard
        // above is vacuous). A guard nobody has seen fail is not yet a guard.
        $this->assertFalse(
            $this->isReferenced('App\\Actions\\Nowhere\\NeverCalledAction', 'NeverCalledAction', 'app/Actions/Nowhere/NeverCalledAction.php'),
        );
    }

    public function test_a_docblock_mention_does_not_count_as_usage(): void
    {
        // The exact false negative that motivated this: CommitStockTake/RefundDispensation looked reachable
        // because other files named them in transaction-boundary docblocks. A comment-only reference is not use.
        $code = "<?php\n/** See RefundDispensation for the boundary. */\n// new RefundDispensation would go here\n\$x = 1;\n";
        $this->assertStringNotContainsString('RefundDispensation', $this->stripComments($code));
    }

    public function test_a_class_reached_only_via_a_filament_or_command_entry_point_is_not_flagged(): void
    {
        // Real entry points count: these actions are reached ONLY from a Filament page / relation-manager /
        // scheduled command, never from another action — the guard must see them as reached.
        $classes = $this->classesIn('app/Actions');
        foreach (['App\\Actions\\Pricing\\SaveGeneticPrice', 'App\\Actions\\Members\\WaiveCarencia'] as $fqcn) {
            $meta = $classes[$fqcn];
            $this->assertTrue($this->isReferenced($fqcn, $meta['short'], $meta['file']), "{$fqcn} should be reachable via its entry point.");
        }
    }

    // --- Detection -------------------------------------------------------------

    /**
     * Every concrete class under $dir, keyed by FQCN.
     *
     * @return array<string, array{short: string, file: string}>
     */
    private function classesIn(string $dir): array
    {
        $classes = [];
        foreach ($this->phpFilesIn(base_path($dir)) as $file) {
            $src = file_get_contents($file);
            if (preg_match('/namespace\s+([^;]+);/', $src, $ns) && preg_match('/\nclass\s+(\w+)/', $src, $cn)) {
                $classes[trim($ns[1]).'\\'.$cn[1]] = ['short' => $cn[1], 'file' => $file];
            }
        }

        return $classes;
    }

    /** Is $fqcn referenced (import, `new`, or `::`) from any non-test file under app/routes/seeders, comments stripped? */
    private function isReferenced(string $fqcn, string $short, string $ownFile): bool
    {
        foreach ($this->searchCorpus() as $file => $code) {
            if ($file === $ownFile) {
                continue; // a class's own file does not count as reaching itself
            }
            if (str_contains($code, 'use '.$fqcn.';') || str_contains($code, 'new '.$short) || str_contains($code, $short.'::')) {
                return true;
            }
        }

        return false;
    }

    /** A permission is "checked" if its literal string appears in app/ or routes/ (NOT the declaration or the role seeder, which only ASSIGN it). */
    private function permissionIsChecked(string $permission): bool
    {
        foreach ($this->searchCorpus() as $file => $code) {
            if (str_contains($file, 'Permissions.php') || str_contains($file, 'seeders')) {
                continue;
            }
            if (str_contains($code, "'".$permission."'") || str_contains($code, '"'.$permission.'"')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function declaredPermissions(): array
    {
        /** @var list<string> $all */
        $all = (new ReflectionClass(Permissions::class))->getConstant('ALL');

        return $all;
    }

    /**
     * The comment-stripped corpus (app/, routes/, database/seeders/), cached for the test run.
     *
     * @return array<string, string>
     */
    private function searchCorpus(): array
    {
        static $corpus = null;
        if ($corpus !== null) {
            return $corpus;
        }

        $corpus = [];
        foreach (['app', 'routes', 'database/seeders'] as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $file) {
                $corpus[$file] = $this->stripComments(file_get_contents($file));
            }
        }

        return $corpus;
    }

    /** Strip T_COMMENT / T_DOC_COMMENT so a docblock/inline mention never counts as usage. */
    private function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function phpFilesIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }
}
