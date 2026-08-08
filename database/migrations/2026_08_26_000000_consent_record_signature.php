<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 220 — where the applicant's drawn signature lives once they are a member.
 *
 * The signature is evidence OF THE CONSENT, so it belongs on the consent row alongside the two other facts
 * about the moment of consent that prompt 210 put there (`channel`, `attested_by`). Without it, a consent
 * saying SIGNED points at nothing a DPO could produce: the image would only exist on the source application's
 * payload, which erasure never walks to (it hangs off `resulting_member_id`, not `member_id`).
 *
 * Nullable, and null is the norm: every consent captured before this branch, every paper-register import,
 * and every club that keeps `signature_on_application` off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_records', function (Blueprint $table): void {
            $table->string('signature_path')->nullable()->after('attested_by');
        });
    }

    public function down(): void
    {
        Schema::table('consent_records', function (Blueprint $table): void {
            $table->dropColumn('signature_path');
        });
    }
};
