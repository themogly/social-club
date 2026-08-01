<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 79 — the audit log list is `WHERE organisation_id = ? ORDER BY created_at DESC` (+ date-range
 * filters), scoped in AuditLogResource::getEloquentQuery(). The only existing index, (action, created_at),
 * is led by `action`, so by the leftmost-prefix rule it cannot serve that org-scoped ordering — the query
 * the audit measured as slow. This composite, led by organisation_id then created_at, serves both the org
 * filter and the created_at ordering/range. The (action, created_at) index stays for action-filtered views.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['organisation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['organisation_id', 'created_at']);
        });
    }
};
