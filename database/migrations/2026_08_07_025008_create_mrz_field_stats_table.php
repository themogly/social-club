<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 179 — the read rate, measured from real use.
 *
 * Prompt 128 gated the prefill on measuring an MRZ read rate ≥~90% against a corpus of real ID photos,
 * which is itself Article 9 material somebody has to gather, hold and destroy. This answers that gate a
 * different way: how often a prefilled field is CORRECTED is the read rate, on real documents, in real
 * conditions, judged by the only people who can tell whether it was right.
 *
 * COUNTS ONLY. No document number, no name, no date of birth, no application id, no timestamp finer than
 * the row's own — nothing here can reconstruct a person or a document. "document_number was corrected 31
 * times out of 104 prefills" is the whole of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mrz_field_stats', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('field', 64);
            $table->unsignedInteger('prefills')->default(0);
            $table->unsignedInteger('corrections')->default(0);
            $table->timestamps();

            $table->unique(['organisation_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mrz_field_stats');
    }
};
