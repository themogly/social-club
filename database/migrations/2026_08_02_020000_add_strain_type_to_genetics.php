<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 66 — sativa / indica / hybrid, a strain variety on the genetic. Nullable: some products
 * (edibles, CBD-dominant) legitimately have no strain type. User-set, unlike the derived unit_type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('genetics', function (Blueprint $table): void {
            $table->string('strain_type')->nullable()->after('product_type');
        });
    }

    public function down(): void
    {
        Schema::table('genetics', function (Blueprint $table): void {
            $table->dropColumn('strain_type');
        });
    }
};
