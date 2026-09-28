<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 282 — the club's own name for a batch ("Cosecha verano 2026"). Free text, not unique, editable; the lote
 * number (`batch_no`) stays the fixed traceability key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table): void {
            $table->string('label', 60)->nullable()->after('batch_no');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table): void {
            $table->dropColumn('label');
        });
    }
};
