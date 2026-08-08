<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WHY the club forwent a fee (prompt 219). Nullable, because every payment written before this — and
        // every real payment after it — has no reason to give: a waiver is the only method that requires one,
        // and it is required at the boundary that writes it rather than by the column, so an ordinary CASH
        // fee is not forced to invent a sentence.
        Schema::table('membership_fee_payments', function (Blueprint $table) {
            $table->string('reason', 255)->nullable()->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('membership_fee_payments', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
