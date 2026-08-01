<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 113 — the access log used to bind only to a MemberDocument row. Member photos and POS signatures are
 * not MemberDocument records, so logging their views needs a polymorphic subject. member_document_id becomes
 * nullable and a (subject_type, subject_id) pair is added, so one log records "who viewed whose Article-9
 * file" whether it is a document, a member photo or a dispensation signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_access_logs', function (Blueprint $table): void {
            $table->string('subject_type')->nullable()->after('member_document_id');
            $table->string('subject_id', 26)->nullable()->after('subject_type');
            $table->index(['subject_type', 'subject_id']);
        });

        // Make the once-mandatory FK nullable so a photo/signature view (no MemberDocument) can be logged.
        // On MySQL the FK must be dropped before the column type/nullability can change, then re-added
        // nullable; SQLite rebuilds the table on ->change() and manages its table-level FK itself.
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        if (! $sqlite) {
            Schema::table('document_access_logs', function (Blueprint $table): void {
                $table->dropForeign(['member_document_id']);
            });
        }

        Schema::table('document_access_logs', function (Blueprint $table): void {
            $table->foreignUlid('member_document_id')->nullable()->change();
        });

        if (! $sqlite) {
            Schema::table('document_access_logs', function (Blueprint $table): void {
                $table->foreign('member_document_id')->references('id')->on('member_documents')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('document_access_logs', function (Blueprint $table): void {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropColumn(['subject_type', 'subject_id']);
        });
    }
};
