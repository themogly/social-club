<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Private disk. Mirrors document_scan_path: the medical certificate that
            // backs a therapeutic member's Article-9 claim, served only via a signed,
            // access-logged URL (never a guessable path).
            $table->string('medical_cert_path')->nullable()->after('document_scan_path');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('medical_cert_path');
        });
    }
};
