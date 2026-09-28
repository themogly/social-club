<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 280 — Hachís is its own product type (Ben's call on 276, which had kept it as CONCENTRATE + subtype HASH). Every
 * existing CONCENTRATE/HASH strain moves to product_type HASH with no subtype. Both are WEIGHT, so unit_type, stock,
 * limits and ceilings are untouched; only the label and the reporting category change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('genetics')
            ->where('product_type', 'CONCENTRATE')->where('concentrate_subtype', 'HASH')
            ->update(['product_type' => 'HASH', 'concentrate_subtype' => null]);
    }

    public function down(): void
    {
        DB::table('genetics')
            ->where('product_type', 'HASH')
            ->update(['product_type' => 'CONCENTRATE', 'concentrate_subtype' => 'HASH']);
    }
};
