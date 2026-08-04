<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 137 — the two things a convocatoria did not yet record so an acta can be DRAFTED from what happened
 * rather than retyped: attendance (in person or by proxy, per member of the FROZEN roll) and each agenda item's
 * resolution with its show-of-hands counts. Both hang off the convocatoria (which is the assembly). A NAME
 * snapshot is stored so the acta and the anonymisation redaction survive a later member scrub, exactly as
 * convocatoria_recipients does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assembly_attendances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('convocatoria_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');                                   // snapshot (redacted on anonymisation)
            $table->string('mode');                                   // PRESENT | PROXY
            $table->string('proxy_holder')->nullable();               // who holds the proxy, when mode = PROXY
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['convocatoria_id', 'member_id']);         // a member attends once
        });

        Schema::create('assembly_resolutions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('convocatoria_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');                      // agenda order
            $table->string('title');                                  // agenda item text snapshot
            $table->string('result');                                 // APPROVED | REJECTED | DEFERRED
            $table->unsignedInteger('votes_for')->default(0);
            $table->unsignedInteger('votes_against')->default(0);
            $table->unsignedInteger('votes_abstain')->default(0);
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['convocatoria_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assembly_resolutions');
        Schema::dropIfExists('assembly_attendances');
    }
};
