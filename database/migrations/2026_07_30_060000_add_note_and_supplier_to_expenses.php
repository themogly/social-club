<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Free-text reason for petty cash / overheads (the prompt-13 "reason").
            $table->string('note')->nullable()->after('receipt_path');
            // Overheads (rent, utilities, refurb) may name a supplier; purchases already do.
            $table->foreignUlid('supplier_id')->nullable()->after('note')
                ->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('note');
        });
    }
};
