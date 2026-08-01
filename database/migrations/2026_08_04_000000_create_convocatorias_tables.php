<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 88 — the club can now convene a general assembly and email the membership. A convocatoria is the
 * formal notice; its recipient roll is FROZEN at issue (the members as-at the notice date) and stored, never
 * recomputed — the point is to evidence WHO was convened, which cannot change afterwards even as the
 * membership does. Each recipient snapshots the member's number/name/email at that instant and records
 * whether they were notified, so members without an email are visibly un-notified rather than silently
 * dropped. The acta later references the convocatoria that called the meeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convocatorias', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete(); // null = whole org
            $table->string('type');                                   // ORDINARY | EXTRAORDINARY
            $table->string('title');
            $table->json('agenda')->nullable();                       // orden del día
            $table->longText('body')->nullable();                     // notice text / development
            $table->string('venue')->nullable();                      // where the assembly is held
            $table->timestamp('held_at');                             // first call
            $table->timestamp('second_call_at')->nullable();          // segunda convocatoria
            $table->unsignedInteger('notice_days')->nullable();       // notice period FROZEN at issue
            $table->unsignedInteger('quorum_required')->nullable();   // first-call quorum FROZEN at issue
            $table->unsignedInteger('roll_count')->nullable();        // members on the frozen roll
            $table->timestamp('issued_at')->nullable();               // null = draft; set once, immutable after
            $table->foreignUlid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();                                    // a DRAFT may be discarded; an issued one may not
            $table->index(['organisation_id', 'held_at']);
        });

        Schema::create('convocatoria_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('convocatoria_id')->constrained()->cascadeOnDelete();
            // nullOnDelete: removing a member never erases the evidence that they were convened — the
            // snapshot columns below preserve who it was.
            $table->foreignUlid('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('member_no');                              // snapshot at issue
            $table->string('name');                                   // snapshot at issue
            $table->string('email')->nullable();                      // snapshot at issue
            $table->string('status');                                 // NOTIFIED | NO_EMAIL
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['convocatoria_id', 'member_id']);
            $table->index('convocatoria_id');
        });

        Schema::table('minutes', function (Blueprint $table): void {
            $table->foreignUlid('convocatoria_id')->nullable()->after('supersedes_id')->constrained('convocatorias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('minutes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('convocatoria_id');
        });
        Schema::dropIfExists('convocatoria_recipients');
        Schema::dropIfExists('convocatorias');
    }
};
