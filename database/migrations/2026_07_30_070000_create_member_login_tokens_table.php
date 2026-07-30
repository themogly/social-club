<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Passwordless member login: a single-use, short-lived magic-link token. Only the
        // SHA-256 hash is stored (the raw token lives only in the emailed link), so a
        // database read can never mint a login.
        Schema::create('member_login_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('requested_ip')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'used_at']);
        });

        // The member guard supports long-lived "remember me" sessions on a trusted device.
        Schema::table('members', function (Blueprint $table) {
            $table->rememberToken();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('remember_token');
        });
        Schema::dropIfExists('member_login_tokens');
    }
};
