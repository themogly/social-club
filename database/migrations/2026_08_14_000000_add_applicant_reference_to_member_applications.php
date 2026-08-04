<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 149 — a hand-over invitation (no email) had nothing identifying it: you could not tell who it was
 * for, resend it, or trace a circulating link. This adds an OPTIONAL reference (a name, or the referring
 * member) so every invitation is attributable. Existing PENDING applications are untouched — the column is
 * nullable and they keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_applications', function (Blueprint $table): void {
            $table->string('applicant_reference')->nullable()->after('applicant_email');
        });
    }

    public function down(): void
    {
        Schema::table('member_applications', function (Blueprint $table): void {
            $table->dropColumn('applicant_reference');
        });
    }
};
