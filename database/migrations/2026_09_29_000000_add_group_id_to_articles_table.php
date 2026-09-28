<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 297 — products created together at several sedes share a `group_id`, so an edit can be applied to the sedes the
 * owner ticks. Nullable: a product made at one sede, and every product that existed before, has none (no guessing by
 * name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->ulid('group_id')->nullable()->after('location_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropColumn('group_id');
        });
    }
};
