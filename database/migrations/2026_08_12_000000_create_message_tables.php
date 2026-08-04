<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 136 — a way for a member to reach the club and have it be EVIDENCE. A thread belongs to one member;
 * messages hang off it, each authored by the member or by the club. This is a contact channel, never an
 * ordering channel: a message carries free text only, never an article/genetic/quantity.
 *
 * A thread can be CONVERTED into a formal RGPD data request (data_request_id) — the moment a member asks in
 * words for their data or its erasure, it becomes a logged obligation, not a message that can be forgotten.
 *
 * The member's subject + their own message bodies are member-authored PII, so anonymisation redacts them
 * (handled in AnonymiseMember) — the thread + timestamps survive as evidence the contact happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');                                // member-authored (redacted on anonymisation)
            $table->string('status')->default('OPEN');                // OPEN | CLOSED
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('data_request_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organisation_id', 'status', 'last_message_at']);
            $table->index(['member_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->string('author');                                 // MEMBER | STAFF
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete(); // the staff author, when STAFF
            $table->text('body');                                     // member-authored bodies redacted on anonymisation
            $table->timestamp('read_at')->nullable();                 // when the club first read a member message
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
    }
};
