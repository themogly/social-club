<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 45 — a nullable applicant email so a tokenised invite can be EMAILED to the prospect (and
 * re-sent), not only copied and shared by hand. Optional: an invite may still be link-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_applications', function (Blueprint $table): void {
            $table->string('applicant_email')->nullable()->after('invited_by');
        });
    }

    public function down(): void
    {
        Schema::table('member_applications', function (Blueprint $table): void {
            $table->dropColumn('applicant_email');
        });
    }
};
