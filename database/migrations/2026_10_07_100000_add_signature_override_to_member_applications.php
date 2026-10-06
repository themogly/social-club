<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 361 — a form filled in on the club's tablet may be sent without a signature («¿Enviar sin firma?»), marked
 * `signature_missing`; back at the counter staff either have it signed there and then or carry on without it, with a
 * reason («Seguir sin firma»): who, when and why, so the member record can say so and it can be collected later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_applications', function (Blueprint $table): void {
            $table->boolean('signature_missing')->default(false);
            $table->foreignUlid('signature_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signature_override_reason', 255)->nullable();
            $table->timestamp('signature_override_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('member_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signature_override_by');
            $table->dropColumn(['signature_missing', 'signature_override_reason', 'signature_override_at']);
        });
    }
};
