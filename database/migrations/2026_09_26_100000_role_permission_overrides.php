<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 262 — the club's OWN choices about what STAFF and MANAGER may do, stored apart from the code's defaults.
 *
 * `App\Support\Permissions` keeps the catalogue (which permissions exist) and the defaults (what each role holds out
 * of the box); `csc:sync-permissions` converges the roles on defaults + these overrides on every deploy, so an
 * owner's deliberate grant or revocation is never reverted. One row per (role, permission) that DIFFERS from the
 * default; `granted` says which way. Never an OWNER row — the owner always holds everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission_overrides', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('role', 32);
            $table->string('permission', 100);
            $table->boolean('granted');
            $table->foreignUlid('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['role', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission_overrides');
    }
};
