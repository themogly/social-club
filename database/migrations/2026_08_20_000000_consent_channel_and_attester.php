<?php

use App\Enums\ConsentChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // HOW the consent was captured, and — when the club captured it rather than the applicant — WHO at the
        // club recorded it (prompt 210). Before this branch there was one way for a consent to exist: the
        // applicant ticked two boxes in a session they controlled, on the public form or on the handed-over
        // tablet. Adding a staff-typed sign-up route means the club can now be the one asserting it, and an
        // assertion and a tick are materially different things to hold in an inspection.
        //
        // Existing rows keep the APPLICANT default and do not change meaning: every consent recorded before
        // this migration WAS the applicant's own act. The one exception in the codebase is the paper-register
        // import (prompt 131), which records a version the member signed on paper — but those rows are
        // historical statements about a signature the club holds, which is exactly what PAPER means, and they
        // are not retro-labelled here because guessing at old rows is the mistake prompt 153's migration
        // deliberately avoided.
        Schema::table('consent_records', function (Blueprint $table) {
            $table->string('channel', 16)->default(ConsentChannel::APPLICANT->value)->after('locale');
            $table->ulid('attested_by')->nullable()->after('channel');
            $table->foreign('attested_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consent_records', function (Blueprint $table) {
            $table->dropForeign(['attested_by']);
            $table->dropColumn(['channel', 'attested_by']);
        });
    }
};
