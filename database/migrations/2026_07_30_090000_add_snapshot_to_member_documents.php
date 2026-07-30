<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            // The FROZEN inputs a generated document was rendered from (name, doc number,
            // consent version, template version…). The stored PDF is the artifact; this is
            // its reproducible, immutable record — later edits to the member or template
            // never touch it.
            $table->json('snapshot')->nullable()->after('generated_from');
        });
    }

    public function down(): void
    {
        Schema::table('member_documents', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};
