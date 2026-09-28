<?php

use App\Support\LoteSeqBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 298 — the number of a lote within its strain, organisation-wide, shared by the parts of a lote. New batches get
 * it at intake; existing ones are numbered here in order of first intake ({@see LoteSeqBackfill}). No `batch_no` changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedInteger('lote_seq')->nullable()->after('batch_no');
            $table->index(['genetic_id', 'lote_seq']);
        });

        LoteSeqBackfill::run();
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropIndex(['genetic_id', 'lote_seq']);
            $table->dropColumn('lote_seq');
        });
    }
};
