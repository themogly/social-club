<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prompt 186 — two people cannot share a day at the till.
 *
 * `TillSessionStatus` had exactly OPEN and CLOSED, so a till was either taking money or finished for the
 * day and there was no state for *the person changed*. A shift change therefore left two bad options:
 * close the day and open a new session (two arqueos for one trading day, and the day's figures become a
 * reconstruction), or leave the session open and let the next person work inside it — which is what would
 * actually happen, and which destroys the one thing an arqueo is for. **A cash variance is attributable to
 * whoever held the drawer.** Share a session between two people and a shortfall belongs to nobody.
 *
 * The owner's decision: **the drawer belongs to the TILL, and a handover is counted.** So a shift change is
 * a count and a signature, and the session and the trading day continue as ONE arqueo.
 *
 * A shift is therefore a RECORD INSIDE a session, not a third session status. That follows from the fork:
 * the session is not "between people" — it is continuously open, and the *shift* is what changes. Modelling
 * it this way means every report that reconciles against a session is untouched, which is most of why a
 * single-operator club notices nothing.
 *
 * The closing count is BLIND, exactly like the session close: `expected_cents` and `variance_cents` are
 * written only after `counted_cents` is submitted. Showing an operator what the drawer should hold before
 * they count it destroys the control, and reusing the close-out's components makes that an easy accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('till_shifts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('till_session_id')->constrained()->cascadeOnDelete();

            // Who held the drawer, and when. This is the whole point of the table.
            $table->foreignUlid('opened_by')->constrained('users');
            $table->timestamp('opened_at');
            $table->foreignUlid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();

            // What the drawer held when this shift TOOK it (the previous shift's count, or the session float
            // for the first), and what the session's ledger expected at that instant. Storing both is what
            // lets a shift's variance be genuinely its own: expected = opening_counted + (ledger movement
            // during MY shift), so a previous operator's shortfall is not inherited as mine. Both are read
            // from TillSummary — the one existing source — never recomputed here.
            $table->unsignedBigInteger('opening_counted_cents');
            $table->unsignedBigInteger('opening_expected_cents');

            // The counted handover figure and the blind-revealed expectation. Integer cents, MoneyCast.
            $table->unsignedBigInteger('counted_cents')->nullable();
            $table->unsignedBigInteger('expected_cents')->nullable();
            $table->bigInteger('variance_cents')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 16);
            $table->timestamps();

            $table->index(['till_session_id', 'status']);
        });

        // Backfill: every session ALREADY open gets a shift, so a till that was open when this deployed
        // does not become one nobody holds. Its opening figures are the session's own float and the float
        // as expected — the only honest reading, since nothing counted the drawer mid-session before now.
        // Without this, `hasOpenShift()` would refuse every commit on a live drawer at deploy time.
        $open = DB::table('till_sessions')->where('status', 'OPEN')->get();

        foreach ($open as $session) {
            DB::table('till_shifts')->insert([
                'id' => (string) Str::ulid(),
                'organisation_id' => $session->organisation_id,
                'till_session_id' => $session->id,
                'opened_by' => $session->opened_by,
                'opened_at' => $session->opened_at,
                'opening_counted_cents' => $session->float_cents,
                'opening_expected_cents' => $session->float_cents,
                'status' => 'OPEN',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('till_shifts');
    }
};
