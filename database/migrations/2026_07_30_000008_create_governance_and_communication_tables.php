<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // acta — no update/delete once signed (enforced in the model).
        Schema::create('minutes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('book');                               // ASSEMBLY|BOARD
            $table->unsignedInteger('number');                    // sequential per book
            $table->string('type')->nullable();
            $table->date('held_on')->nullable();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->json('agenda')->nullable();
            $table->json('resolutions')->nullable();
            $table->json('attendees')->nullable();                // member ids
            $table->unsignedInteger('quorum_present')->nullable();
            $table->unsignedInteger('quorum_required')->nullable();
            $table->longText('body')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignUlid('supersedes_id')->nullable()->constrained('minutes')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organisation_id', 'book', 'number']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete(); // null = all
            $table->string('title');
            $table->longText('body')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignUlid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organisation_id', 'published_at']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('GOING');           // GOING|MAYBE|DECLINED
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'member_id']);
        });

        // webpush-compatible (ULID subscribable to match ULID members). Prompt 15 wires the channel.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulidMorphs('subscribable');                   // Member
            $table->text('endpoint');
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('event_rsvps');
        Schema::dropIfExists('events');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('minutes');
    }
};
