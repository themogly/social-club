<?php

/**
 * Integrity harness — the invariants behind prompts 103–113, as runnable assertions.
 *
 *   php audits/integrity-harness.php                  every section
 *   php audits/integrity-harness.php ledger reports   named sections only
 *   php audits/integrity-harness.php --list           what sections exist
 *
 * Exits non-zero if any invariant fails, so it can gate a deploy or run in CI.
 *
 * WHY THIS EXISTS. Every check here corresponds to a defect that a green test suite did
 * not catch, because each one is a property of the SYSTEM rather than of a unit: the
 * till agreeing with the ledger, the dashboard agreeing with the report, the stock
 * ledger summing to the stock. Unit tests assert that a writer writes; these assert that
 * everything that has ever been written still adds up.
 *
 * It reads real data and (except where noted) writes nothing. The two checks that must
 * mutate — post-close Z-report drift and dashboard scope — do so inside a transaction
 * that is always rolled back.
 *
 * Run it against a seeded install, and against production before a release.
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\DispensationStatus;
use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\StockMovement;
use App\Models\TillSession;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\BusinessDay;
use App\Support\Period;
use App\Support\Settings;
use App\Support\Spreadsheet\ReportExport;
use App\Support\StockCeiling;
use App\Support\TillSummary;
use App\Support\Wallet;
use App\Support\ZReport;
use App\ViewModels\DashboardCharts;
use App\ViewModels\Reports\FinancialReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------- reporting

final class Result
{
    public int $passed = 0;

    public int $failed = 0;

    /** @var list<string> */
    public array $failures = [];

    public function check(string $name, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->passed++;
            printf("  \033[32m✓\033[0m %-62s %s\n", $name, $detail);

            return;
        }
        $this->failed++;
        $this->failures[] = $name.($detail === '' ? '' : "  — {$detail}");
        printf("  \033[31m✗\033[0m %-62s %s\n", $name, $detail);
    }

    public function note(string $text): void
    {
        printf("    \033[2m%s\033[0m\n", $text);
    }
}

$R = new Result;
$org = Organisation::query()->first();
if ($org === null) {
    exit("No organisation found — seed the database first.\n");
}
$locationIds = Location::withoutGlobalScopes()->where('organisation_id', $org->id)->pluck('id')->all();
$money = fn (int $cents): string => number_format($cents / 100, 2, ',', '.').' €';

// ---------------------------------------------------------------- sections

$sections = [];

/**
 * Every batch and article must reconcile to its own movement ledger. A stock figure that
 * does not equal the sum of its movements means something wrote stock without recording
 * why — which is the whole point of having a ledger. (Prompt 104.)
 */
$sections['ledger'] = function (Result $R) use ($money): void {
    $bad = 0;
    $n = 0;
    foreach (Batch::withoutGlobalScopes()->get() as $b) {
        $n++;
        $unit = $b->isUnitType();
        $ledger = (int) StockMovement::withoutGlobalScopes()
            ->where('stockable_type', $b->getMorphClass())->where('stockable_id', $b->id)
            ->sum($unit ? 'qty_units' : 'qty_cg');
        $stored = $unit ? (int) ($b->getRawOriginal('remaining_units') ?? 0) : (int) $b->getRawOriginal('remaining_cg');
        if ($ledger !== $stored) {
            $bad++;
            $R->note("batch {$b->batch_no}: ledger {$ledger} vs stored {$stored}");
        }
    }
    $R->check('every batch reconciles to its movement ledger', $bad === 0, "{$n} batches");

    $bad = 0;
    $n = 0;
    foreach (Article::withoutGlobalScopes()->get() as $a) {
        $n++;
        $ledger = (int) StockMovement::withoutGlobalScopes()
            ->where('stockable_type', $a->getMorphClass())->where('stockable_id', $a->id)->sum('qty_units');
        if ($ledger !== (int) $a->stock) {
            $bad++;
            $R->note("article {$a->name}: ledger {$ledger} vs stock {$a->stock}");
        }
    }
    $R->check('every article reconciles to its movement ledger', $bad === 0, "{$n} articles");

    $neg = Batch::withoutGlobalScopes()->where('remaining_cg', '<', 0)->count()
         + Batch::withoutGlobalScopes()->where('remaining_units', '<', 0)->count()
         + Article::withoutGlobalScopes()->where('stock', '<', 0)->count();
    $R->check('no stock is negative', $neg === 0);

    // Wallet: the stored running balance must equal a replay of the ledger.
    $rows = 0;
    $bad = 0;
    foreach (Member::withoutGlobalScopes()->pluck('id') as $memberId) {
        foreach (WalletTransaction::withoutGlobalScopes()->where('member_id', $memberId)
            ->select('location_id')->distinct()->pluck('location_id') as $loc) {
            $running = 0;
            foreach (WalletTransaction::withoutGlobalScopes()
                ->where('member_id', $memberId)->where('location_id', $loc)
                ->orderBy('created_at')->orderBy('id')->get() as $t) {
                $running += (int) $t->getRawOriginal('amount_cents');
                $rows++;
                if ((int) $t->getRawOriginal('balance_after_cents') !== $running) {
                    $bad++;
                }
            }
            if (Wallet::balance($memberId, $loc) !== $running) {
                $bad++;
                $R->note("Wallet::balance disagrees with the ledger for member {$memberId}");
            }
        }
    }
    $R->check('wallet balance_after_cents replays exactly', $bad === 0, "{$rows} transactions");

    // Money that must add up on its own row.
    $bad = 0;
    $n = 0;
    foreach (DB::table('dispensations')->get() as $d) {
        $n++;
        $lines = (int) DB::table('dispensation_lines')->where('dispensation_id', $d->id)->sum('line_total_cents');
        if ($lines !== (int) $d->total_cents || (int) $d->cash_cents + (int) $d->wallet_cents !== (int) $d->total_cents) {
            $bad++;
        }
    }
    $R->check('dispensation header = lines, and cash + wallet = total', $bad === 0, "{$n} dispensations");

    $bad = 0;
    foreach (DB::table('orders')->get() as $o) {
        if ((int) $o->cash_cents + (int) $o->wallet_cents !== (int) $o->total_cents) {
            $bad++;
        }
    }
    $R->check('cash + wallet = total on every bar order', $bad === 0);
};

/**
 * A closed till session is a signed document. Its stored figures must agree with each
 * other, and reprinting it must not produce a different number. (Prompts 103, 106.)
 */
$sections['till'] = function (Result $R) use ($money): void {
    $closed = TillSession::withoutGlobalScopes()->whereNotNull('expected_cents')->get();

    $bad = 0;
    foreach ($closed as $s) {
        $stored = (int) $s->getRawOriginal('expected_cents');
        $recomputed = TillSummary::expectedCents($s);
        if ($stored !== $recomputed) {
            $bad++;
            $R->note(sprintf('%s %s: stored %s vs recomputed %s',
                $s->terminal, $s->closed_at?->toDateString(), $money($stored), $money($recomputed)));
        }
    }
    $R->check('closed tills match a fresh TillSummary recomputation', $bad === 0, $closed->count().' sessions');

    $bad = 0;
    foreach ($closed as $s) {
        $calc = (int) $s->getRawOriginal('counted_cents') - (int) $s->getRawOriginal('expected_cents');
        if ((int) $s->getRawOriginal('variance_cents') !== $calc) {
            $bad++;
        }
    }
    $R->check('variance = counted − expected on every closed till', $bad === 0);

    // The Z-report as a whole must be internally consistent for a closed session.
    $bad = 0;
    foreach ($closed as $s) {
        $z = ZReport::for($s);
        if ((int) $z['counted'] - (int) $z['expected'] !== (int) $z['variance']) {
            $bad++;
        }
    }
    $R->check('ZReport counted − expected = variance on closed sessions', $bad === 0);

    // Every till-cash expense must have a matching cash movement, or the drawer looks over.
    $orphans = 0;
    foreach (Expense::query()->withoutGlobalScopes()
        ->where('paid_from', ExpensePaidFrom::TILL_CASH->value)->whereNotNull('till_session_id')->get() as $e) {
        $match = DB::table('cash_movements')->where('till_session_id', $e->till_session_id)
            ->where('type', 'PETTY_CASH')->where('amount_cents', -1 * (int) $e->getRawOriginal('amount_cents'))->exists();
        if (! $match) {
            $orphans++;
        }
    }
    $R->check('every till-cash expense has its PETTY_CASH movement', $orphans === 0, "{$orphans} orphaned");

    // Reprinting a closed Z after a post-close void must not move the figure. Rolled back.
    $subject = $closed->first(fn (TillSession $s): bool => Dispensation::withoutGlobalScopes()
        ->where('till_session_id', $s->id)->where('status', DispensationStatus::COMPLETED->value)
        ->where('cash_cents', '>', 0)->exists());

    if ($subject === null) {
        $R->note('no closed session with a completed CASH sale — post-close drift check skipped');

        return;
    }
    $before = (int) ZReport::for($subject)['expected'];
    DB::beginTransaction();
    // Only CASH reaches the drawer, so void the largest cash sale or the check proves nothing.
    $victim = Dispensation::withoutGlobalScopes()->where('till_session_id', $subject->id)
        ->where('status', DispensationStatus::COMPLETED->value)
        ->orderByDesc('cash_cents')->first();
    DB::table('dispensations')->where('id', $victim->id)->update(['status' => DispensationStatus::VOIDED->value]);
    $after = (int) ZReport::for(TillSession::withoutGlobalScopes()->find($subject->id))['expected'];
    DB::rollBack();

    $R->check('a post-close void does not change a closed Z-report', $before === $after,
        $before === $after ? '' : 'moved by '.$money($after - $before));
};

/**
 * The dashboard and the financial report must tell the same money story, and soft-deleted
 * rows must not be counted anywhere that aggregates. (Prompt 107.)
 */
$sections['dashboard'] = function (Result $R) use ($org, $money): void {
    $period = Period::thisMonth();
    $gastos = function (bool $dashboard) use ($org, $period): int {
        if ($dashboard) {
            return array_sum((new DashboardCharts($org->id, null, $period))->incomeVsExpenses()['gastos']);
        }
        foreach ((new FinancialReport($org->id, null, $period))->tables() as $t) {
            if ($t->key === 'takings') {
                return (int) $t->totals['gastos'];
            }
        }

        return 0;
    };

    $R->check('dashboard outgoings = report outgoings (baseline)', $gastos(true) === $gastos(false),
        $money($gastos(true)).' vs '.$money($gastos(false)));

    DB::beginTransaction();
    $cat = ExpenseCategory::query()->first();
    $actor = User::query()->first();
    if ($cat !== null && $actor !== null) {
        Expense::create([
            'organisation_id' => $org->id, 'location_id' => null, 'category_id' => $cat->id,
            'amount_cents' => 120000, 'paid_from' => ExpensePaidFrom::BANK, 'kind' => ExpenseKind::OVERHEAD,
            'till_session_id' => null, 'recorded_by' => $actor->id,
            'incurred_on' => CarbonImmutable::now()->startOfMonth()->addDays(2)->toDateString(),
        ]);
        $R->check('an org-level overhead reaches the dashboard', $gastos(true) === $gastos(false),
            'rent 1.200,00 € → dashboard '.$money($gastos(true)).', report '.$money($gastos(false)));
    }
    DB::rollBack();

    DB::beginTransaction();
    $charts = new DashboardCharts($org->id, null, $period);
    $before = array_sum($charts->stockLevelsByGenetic()['grams']);
    $batch = Batch::withoutGlobalScopes()->where('status', 'OPEN')->first();
    if ($batch !== null) {
        $batch->delete();
        $after = array_sum((new DashboardCharts($org->id, null, $period))->stockLevelsByGenetic()['grams']);
        $R->check('a soft-deleted batch leaves the stock chart', abs($before - $after) > 0.004,
            sprintf('%.2f g → %.2f g', $before, $after));
    }
    DB::rollBack();
};

/**
 * Reports must define "a day" the way the gram cap and the Z-report do. A club open past
 * midnight puts real trade in the gap. (Prompt 105.)
 */
$sections['dayboundary'] = function (Result $R): void {
    foreach (Location::withoutGlobalScopes()->get() as $loc) {
        $probe = CarbonImmutable::now()->setTime(12, 0);
        [$bdStart] = BusinessDay::window($loc, $probe);
        $calendarStart = $probe->setTimezone(config('app.timezone') ?: 'UTC')->startOfDay();
        $hours = (int) round(abs($bdStart->getTimestamp() - $calendarStart->getTimestamp()) / 3600);
        $R->check("'today' agrees for {$loc->name}", $hours === 0,
            $hours === 0 ? '' : "business day and report day are {$hours} h apart");
    }

    // Half-open bounds compared inclusively double-count a boundary-instant row.
    $p = Period::thisMonth();
    $prev = $p->previous();
    $sum = DB::table('dispensations')->whereBetween('dispensed_at', [$p->start, $p->end])->count()
         + DB::table('dispensations')->whereBetween('dispensed_at', [$prev->start, $prev->end])->count();
    $union = DB::table('dispensations')->whereBetween('dispensed_at', [$prev->start, $p->end])->count();
    $R->check('no row is counted in two adjacent periods', $sum === $union,
        $sum === $union ? '' : ($sum - $union).' double-counted at the boundary');
};

/**
 * The legal on-site ceiling must be derived from the members of THAT sede. (Prompt 110.)
 */
$sections['ceiling'] = function (Result $R): void {
    $seen = [];
    foreach (Location::withoutGlobalScopes()->get() as $loc) {
        $c = StockCeiling::forLocation($loc);
        $atSede = DB::table('memberships')->where('location_id', $loc->id)
            ->where('status', 'ACTIVE')->distinct()->count('member_id');
        $seen[] = $c['active_members'];
        $R->check("{$loc->name}: ceiling uses this sede's members", $c['active_members'] === $atSede,
            "counted {$c['active_members']}, active here {$atSede}");
        $R->check("{$loc->name}: on-site stock is within the ceiling", ! $c['exceeded'],
            sprintf('%.2f g on site, ceiling %.2f g', $c['on_site_cg'] / 100, $c['ceiling_cg'] / 100));
    }
    if (count($seen) > 1 && count(array_unique($seen)) === 1) {
        $R->note('every sede produced the same member count — check this is genuinely true, not org-wide');
    }
};

/**
 * Controls the club can configure must actually bind. (Prompt 111.)
 */
$sections['wiring'] = function (Result $R): void {
    $strip = function (string $path): string {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    };

    $root = dirname(__DIR__);
    $sources = [];
    foreach (['app', 'routes', 'database/seeders', 'resources/views'] as $dir) {
        if (! is_dir("{$root}/{$dir}")) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}")) as $f) {
            if (! $f->isFile() || ! str_ends_with($f->getPathname(), '.php')) {
                continue;
            }
            $rel = str_replace("{$root}/", '', $f->getPathname());
            $sources[$rel] = str_ends_with($rel, '.blade.php') ? file_get_contents($f->getPathname()) : $strip($f->getPathname());
        }
    }
    // A key is "read" if anything outside the two declaring files mentions it, OR if a
    // helper inside Settings.php reads it (formatMemberNumber does) — which shows up as a
    // SECOND occurrence beyond the one in the DEFAULTS array.
    $declaring = ['app/Support/Settings.php', 'app/Filament/Pages/ManageSettings.php'];
    $referenced = function (string $needle) use ($sources): bool {
        foreach ($sources as $file => $src) {
            $n = substr_count($src, "'{$needle}'") + substr_count($src, "\"{$needle}\"");
            if ($n === 0) {
                continue;
            }
            if ($file === 'app/Support/Settings.php') {
                if ($n > 1) {
                    return true;   // declared once in DEFAULTS, plus a real read
                }

                continue;
            }
            if ($file === 'app/Filament/Pages/ManageSettings.php') {
                continue;          // typing + rendering the field is not reading it
            }

            return true;
        }

        return false;
    };

    $phantom = array_values(array_filter(array_keys(Settings::DEFAULTS), fn (string $k): bool => ! $referenced($k)));
    $R->check('every settings key is read somewhere', $phantom === [],
        $phantom === [] ? count(Settings::DEFAULTS).' keys' : 'never read: '.implode(', ', $phantom));

    $unused = [];
    foreach (\Spatie\Permission\Models\Permission::query()->pluck('name') as $perm) {
        $found = false;
        foreach ($sources as $file => $src) {
            if (str_contains($file, 'Seeder')) {
                continue;
            }
            if (str_contains($src, "'{$perm}'") || str_contains($src, "\"{$perm}\"")) {
                $found = true;
                break;
            }
        }
        if (! $found) {
            $unused[] = $perm;
        }
    }
    $R->check('every permission is checked outside the seeders', $unused === [],
        $unused === [] ? \Spatie\Permission\Models\Permission::count().' permissions' : implode(', ', $unused));

    // A column name that does not exist degrades to a string literal on SQLite and
    // returns 0 instead of throwing. This finds the typo before production does.
    $columns = [];
    foreach (Schema::getTableListing() as $t) {
        $t = str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t;
        $columns = array_merge($columns, Schema::getColumnListing($t));
    }
    $columns = array_unique($columns);
    $suspect = [];
    foreach ($sources as $file => $src) {
        if (! str_starts_with($file, 'app/')) {
            continue;
        }
        if (preg_match_all('/->(sum|avg|max|min|whereNull|whereNotNull)\(\s*[\'"]([a-z_][a-z0-9_]*)[\'"]/i', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as [$whole, $method, $col]) {
                if (! in_array($col, $columns, true)) {
                    $suspect[] = "{$file}: ->{$method}('{$col}')";
                }
            }
        }
    }
    $R->check('no aggregate references a column that does not exist', $suspect === [],
        $suspect === [] ? count($columns).' columns checked' : implode('; ', array_slice($suspect, 0, 3)));
};

/**
 * The reports must agree with independently computed SQL, with each other, and with what
 * they export. (Prompts 107, 108.)
 */
$sections['reports'] = function (Result $R) use ($org, $locationIds, $money): void {
    $period = Period::thisMonth();
    [$start, $end] = $period->bounds();
    $fin = new FinancialReport($org->id, $locationIds, $period);
    $total = function (string $key, string $col) use ($fin): int {
        foreach ($fin->tables() as $t) {
            if ($t->key === $key) {
                return (int) ($t->totals[$col] ?? 0);
            }
        }

        return 0;
    };

    $disp = (int) DB::table('dispensations')->whereIn('location_id', $locationIds)
        ->where('status', 'COMPLETED')->whereBetween('dispensed_at', [$start, $end])->sum('total_cents');
    $ord = (int) DB::table('orders')->whereIn('location_id', $locationIds)
        ->where('status', 'COMPLETED')->whereBetween('created_at', [$start, $end])->sum('total_cents');

    $R->check('report aportaciones = raw dispensation totals', $total('takings', 'aportaciones') === $disp, $money($disp));
    $R->check('report barra = raw order totals', $total('takings', 'barra') === $ord, $money($ord));
    $R->check('payment-method total = takings ingresos',
        $total('methods', 'importe') === $total('takings', 'ingresos'), $money($total('takings', 'ingresos')));
    $R->check('expense breakdown = takings gastos',
        $total('expenses', 'importe') === $total('takings', 'gastos'), $money($total('takings', 'gastos')));

    // Exports must carry the same figures, in both languages, with no raw enum values.
    $rawEnums = ['COMPLETED', 'VOIDED', 'ACTIVE', 'EXPELLED', 'PENDING', 'CLOSED', 'CASH', 'WALLET',
        'BANK', 'CARD', 'INTAKE', 'DISPENSE', 'ADJUSTMENT', 'MERMA', 'TILL_CASH', 'SATIVA', 'INDICA', 'HYBRID'];
    $original = app()->getLocale();
    $issues = [];
    $checked = 0;
    foreach (['es', 'en'] as $locale) {
        app()->setLocale($locale);
        foreach ((new FinancialReport($org->id, $locationIds, $period))->tables() as $t) {
            $csv = ReportExport::csv($t);
            if (! str_starts_with($csv, "\xEF\xBB\xBF")) {
                $issues[] = "{$t->key} ({$locale}): missing UTF-8 BOM";
            }
            foreach ($rawEnums as $raw) {
                if (preg_match('/(^|[;,"\s])'.preg_quote($raw, '/').'([;,"\s]|$)/', $csv)) {
                    $issues[] = "{$t->key} ({$locale}): raw enum {$raw}";
                    break;
                }
            }
            $checked++;
        }
    }
    app()->setLocale($original);
    $R->check('CSV exports carry a BOM and no raw enum values', $issues === [],
        $issues === [] ? "{$checked} tables × 2 locales" : implode('; ', array_slice($issues, 0, 3)));

    // Query volume must not scale with the number of rows being reported.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $tillReport = new \App\ViewModels\Reports\TillReport($org->id, $locationIds, $period);
    $tillReport->tables();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $sessions = max(1, TillSession::withoutGlobalScopes()->whereIn('location_id', $locationIds)
        ->whereBetween('opened_at', [$start, $end])->count());
    $perSession = $queries / $sessions;
    $R->check('the till report does not scale queries with sessions', $perSession < 2.0,
        sprintf('%d queries for %d sessions (%.1f each)', $queries, $sessions, $perSession));

    // Settings must be memoised — the same key read repeatedly is one query, not many.
    DB::flushQueryLog();
    DB::enableQueryLog();
    for ($i = 0; $i < 10; $i++) {
        Settings::get('daily_limit_cg');
    }
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();
    $R->check('reading one settings key ten times is not ten queries', $n <= 2, "{$n} queries");
};

// ---------------------------------------------------------------- runner

$argv = $_SERVER['argv'] ?? [];
$requested = array_values(array_filter(array_slice($argv, 1), fn (string $a): bool => ! str_starts_with($a, '--')));

if (in_array('--list', $argv, true)) {
    echo "Sections: ".implode(', ', array_keys($sections))."\n";
    exit(0);
}

$run = $requested === [] ? array_keys($sections) : $requested;

printf("\n\033[1mIntegrity harness\033[0m — %s, %d location(s), %s\n",
    $org->name, count($locationIds), config('database.default'));

foreach ($run as $name) {
    if (! isset($sections[$name])) {
        printf("\n  unknown section '%s' (have: %s)\n", $name, implode(', ', array_keys($sections)));
        continue;
    }
    printf("\n\033[1m%s\033[0m\n", $name);
    try {
        $sections[$name]($R);
    } catch (Throwable $e) {
        $R->check("{$name} completed", false, get_class($e).': '.substr($e->getMessage(), 0, 90));
    }
}

printf("\n%s%d passed, %d failed\033[0m\n", $R->failed === 0 ? "\033[32m" : "\033[31m", $R->passed, $R->failed);
if ($R->failures !== []) {
    echo "\nFailures:\n";
    foreach ($R->failures as $f) {
        echo "  • {$f}\n";
    }
}

exit($R->failed === 0 ? 0 : 1);
