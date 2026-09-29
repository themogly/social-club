<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 311 — owner alerts. `owner_alert_states` remembers what is currently over the line, so an item that stays low for
 * three days sends ONE message; a person's Telegram chat id is stored encrypted (plus a keyed hash to find it again on
 * `/stop`); one-time link codes are stored hashed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_alert_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('subject', 120); // e.g. "genetic:<ulid>", "article:<ulid>", "component:scheduler"
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->json('detail')->nullable(); // names and quantities for the message — never member data
            $table->timestamp('active_since');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'cleared_at']);
            $table->index(['type', 'subject', 'location_id']);
        });

        Schema::create('telegram_link_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('telegram_chat_id')->nullable();
            $table->string('telegram_chat_hash', 64)->nullable()->index();
            $table->json('alert_preferences')->nullable();
            $table->date('alert_summary_sent_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['telegram_chat_hash']);
            $table->dropColumn(['telegram_chat_id', 'telegram_chat_hash', 'alert_preferences', 'alert_summary_sent_on']);
        });
        Schema::dropIfExists('telegram_link_codes');
        Schema::dropIfExists('owner_alert_states');
    }
};
