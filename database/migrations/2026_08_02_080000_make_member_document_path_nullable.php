<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 76 — a generated member document's `path` becomes nullable so RGPD erasure can null it: a
 * retention-obligation document (declaration / libro de socios) is RETAINED as redacted metadata after its
 * identifying PDF is deleted, so it legitimately has no file. Existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', function (Blueprint $table): void {
            $table->string('path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('member_documents', function (Blueprint $table): void {
            $table->string('path')->nullable(false)->change();
        });
    }
};
