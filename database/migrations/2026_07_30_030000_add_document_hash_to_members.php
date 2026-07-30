<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Blind index: document_number is encrypted (unqueryable), so a deterministic
            // hash of the normalised value enables duplicate detection and uniqueness.
            $table->string('document_hash', 64)->nullable()->after('document_number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('document_hash');
        });
    }
};
