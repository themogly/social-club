<?php

namespace App\Support;

use App\Actions\RecordAuditLog;
use App\Http\Controllers\DispensationReceiptController;
use App\Models\Dispensation;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;

/**
 * Prompt 324 — *Modo formación*: the real counter, with nothing kept.
 *
 * ONE mechanism, not a copy of each flow: while training is on, every counter request runs inside a database
 * transaction that is ALWAYS rolled back ({@see self::run()}, called by `RunCounterTraining`). The action runs for real —
 * validation, limits, prices, stock allocation, idempotency — the screen renders its real result, and then it is
 * discarded. Reads are real (members, catalogue, stock, limits): practice looks exactly like the real thing.
 *
 * What a rollback can't undo is switched off for the request ({@see self::isolate()}): the queue (a null connection),
 * mail (the array mailer), the cache and rate limiters (the array store — nothing a practice visit does can affect a
 * real one afterwards), file writes (`DocumentVault` and uploads refuse), and Telegram.
 *
 * Only the session knows training is on (plus the two audit entries). Each step is rolled back on its own, so practice
 * runs on the real state around it (Ben, 324): a step that needs an earlier PRACTICE write gets the real refusal.
 */
final class TrainingMode
{
    private const KEY = 'counter_training_mode'; // NOT under 'counter.' — a dot key like 'counter.training.x' would nest inside it and read as "on"

    /**
     * The counter's write buttons — `wire:click` / `wire:submit` methods — that read *(práctica)* in training. The
     * suffix is CSS keyed on these names (one place, `counter-chrome`), not an edit to every button.
     *
     * @var list<string>
     */
    public const WRITE_METHODS = [
        'commitDispensation', 'commitOnTab', 'commitWithOverride', 'commitOrder', 'voidLast',
        'checkIn', 'confirmOverride', 'checkOut', 'checkOutAll',
        'collectMemberFee', 'collectFee', 'collectDebt', 'waiveFee', 'renewMembership', 'enrolAtThisSede',
        'submitStaffAlta', 'approveAlta', 'sendAltaInvitation', 'resendAltaInvitation',
        'open', 'recordMovement', 'recordExpense', 'submitReweigh', 'submitCount', 'finishClose', 'handOver',
        'clockInNow', 'clockOutAfterClose', 'confirmClockOutFor', 'declareForgottenEnd', 'undoClockOut',
    ];

    /** The CSS selectors of those buttons' `::after` (clicks, and a form's submit button). */
    public static function writeButtonSelectors(): string
    {
        return collect(self::WRITE_METHODS)->flatMap(fn (string $method): array => [
            '#counter-main [wire\\:click^="'.$method.'"]::after',
            '#counter-main form[wire\\:submit="'.$method.'"] [type="submit"]::after',
            '#counter-main form[wire\\:submit\\.prevent="'.$method.'"] [type="submit"]::after',
        ])->implode(",\n");
    }

    private const RECEIPTS = 'counter_practice_receipts';

    /** Set while a training request's transaction is open: an `end()` inside it is audited after the rollback. */
    private static bool $running = false;

    /** @var list<Closure> */
    private static array $afterRollback = [];

    public static function active(): bool
    {
        return Session::has(self::KEY);
    }

    /** @return array{operator_id: string, location_id: string, started_at: int}|null */
    public static function state(): ?array
    {
        $state = Session::get(self::KEY);

        return is_array($state) ? $state : null;
    }

    public static function start(string $operatorId, string $locationId): void
    {
        Session::put(self::KEY, ['operator_id' => $operatorId, 'location_id' => $locationId, 'started_at' => now()->timestamp]);
        (new RecordAuditLog)->handle('counter.training.started', null, null, ['operator_id' => $operatorId, 'location_id' => $locationId]);
    }

    /**
     * Leave training: forget it, discard the practice basket and receipts, and audit it — AFTER the rollback when this
     * runs inside a training request (a lock, a switch, *Salir*), so the entry survives.
     */
    public static function end(string $reason): void
    {
        $state = self::state();
        if ($state === null) {
            return;
        }

        Session::forget([self::KEY, self::RECEIPTS]);
        CounterBasket::forgetAll();

        $audit = fn () => (new RecordAuditLog)->handle('counter.training.ended', null, null, [
            'operator_id' => $state['operator_id'], 'location_id' => $state['location_id'], 'reason' => $reason,
            'minutes' => (int) floor((now()->timestamp - $state['started_at']) / 60),
        ]);

        self::$running ? self::$afterRollback[] = $audit : $audit();
    }

    /**
     * Run a training request: isolate its side effects, run it inside a transaction, and roll the transaction back
     * whatever happened. Then run what must survive (the `ended` audit).
     *
     * @template T
     *
     * @param  Closure(): T  $request
     * @return T
     */
    public static function run(Closure $request): mixed
    {
        self::isolate();
        $level = DB::transactionLevel();
        DB::beginTransaction();
        self::$running = true;

        self::listenForReceipts();

        try {
            $response = $request();
            // The receipt sheet asks for the receipt in a LATER request, when this one's rows are gone: render it now.
            foreach (array_splice(self::$created, 0) as $id) {
                $dispensation = Dispensation::query()->withoutGlobalScopes()->whereKey($id)->first();
                if ($dispensation !== null) {
                    self::keepReceipt('dispensation', $id, DispensationReceiptController::html($dispensation));
                }
            }

            return $response;
        } finally {
            self::$created = [];
            DB::rollBack($level);
            self::$running = false;
            foreach (array_splice(self::$afterRollback, 0) as $survivor) {
                $survivor();
            }
        }
    }

    /** @var list<string> dispensations created by the current training request */
    private static array $created = [];

    /** The event dispatcher the receipt listener is on (a new app — a test, a long-lived worker — has a new one). */
    private static ?int $listeningOn = null;

    /** Registered once per dispatcher, collecting only inside a training request (never replaces the app's listeners). */
    private static function listenForReceipts(): void
    {
        $dispatcher = spl_object_id(app('events'));
        if (self::$listeningOn === $dispatcher) {
            return;
        }
        self::$listeningOn = $dispatcher;
        Event::listen('eloquent.created: '.Dispensation::class, function (Dispensation $dispensation): void {
            if (self::$running) {
                self::$created[] = (string) $dispensation->id;
            }
        });
    }

    /** Is this code running inside a training request's rolled-back transaction? */
    public static function running(): bool
    {
        return self::$running;
    }

    /** The side effects a rollback can't undo, switched off for this request. */
    private static function isolate(): void
    {
        config(['queue.default' => 'training']);
        Mail::setDefaultDriver('array');
        Cache::setDefaultDriver('array');
        config(['cache.limiter' => 'array']); // the limiter is its own store since 344 — practice never touches the real tally
        app()->forgetInstance(RateLimiter::class);
        Facade::clearResolvedInstance(RateLimiter::class);
    }

    /** A practice receipt, rendered BEFORE the rollback (its rows are gone after it) and kept for the receipt sheet. */
    public static function keepReceipt(string $kind, string $id, string $html): void
    {
        $kept = (array) Session::get(self::RECEIPTS, []);
        $kept[$kind.':'.$id] = $html;
        Session::put(self::RECEIPTS, array_slice($kept, -5, null, true));
    }

    public static function keptReceipt(string $kind, string $id): ?string
    {
        $html = Session::get(self::RECEIPTS.'.'.$kind.':'.$id);

        return is_string($html) ? $html : null;
    }
}
