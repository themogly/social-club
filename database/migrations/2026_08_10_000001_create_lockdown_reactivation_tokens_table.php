<?php

use App\Actions\MemberAuth\IssueMemberLoginLink;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 121 — one of the "ways back", at owner trust level: a single-use token emailed to each owner so they
 * can reactivate FROM THEIR OWN INBOX, off the (possibly coerced) terminal. Only the token HASH is stored; the
 * raw token travels solely in the emailed link (the magic-link pattern, {@see IssueMemberLoginLink}).
 * The other ways back — a time-delayed auto-reactivation and a break-glass CLI command — need no token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lockdown_reactivation_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_lockdown_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();  // the owner this link was mailed to
            $table->string('token_hash');                                       // sha256 of the raw token, never the raw
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();                           // single-use
            $table->timestamps();

            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lockdown_reactivation_tokens');
    }
};
