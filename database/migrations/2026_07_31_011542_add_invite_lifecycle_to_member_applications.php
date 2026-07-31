<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 29 — make invite links manageable. The invite already persists as a PENDING
 * MemberApplication (invite_token_hash); this adds the lifecycle it lacked: the raw
 * token stored ENCRYPTED so the link can be RE-COPIED later (the reported bug — it was
 * hash-only, so lost the moment the one-time toast was dismissed), who issued it, when
 * it expires, when it was opened/submitted (invite status), and a revoke marker. The
 * public verification path (the hash lookup) is unchanged — these are additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_applications', function (Blueprint $table): void {
            $table->text('invite_token')->nullable()->after('invite_token_hash'); // encrypted at rest (cast)
            $table->foreignUlid('invited_by')->nullable()->after('invite_token')->constrained('users')->nullOnDelete();
            $table->timestamp('invite_expires_at')->nullable()->after('invited_by');
            $table->timestamp('opened_at')->nullable()->after('invite_expires_at');   // form first viewed → "started"
            $table->timestamp('submitted_at')->nullable()->after('opened_at');         // payload submitted
            $table->timestamp('revoked_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('member_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['invite_token', 'invite_expires_at', 'opened_at', 'submitted_at', 'revoked_at']);
        });
    }
};
