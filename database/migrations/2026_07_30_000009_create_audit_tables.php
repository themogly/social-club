<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only — no update/delete path (enforced in the model).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->nullableUlidMorphs('auditable');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index(['action', 'created_at']);
        });

        Schema::create('breach_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->timestamp('discovered_at')->nullable();
            $table->string('scope')->nullable();
            $table->timestamp('aepd_notified_at')->nullable();
            $table->string('status')->default('OPEN');            // OPEN|NOTIFIED|CLOSED
            $table->timestamps();
        });

        // Evidences that the club answered a data-subject request in time.
        Schema::create('data_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->string('type');                               // ACCESS|RECTIFY|ERASE|PORTABILITY|OBJECT|RESTRICT
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
        Schema::dropIfExists('breach_logs');
        Schema::dropIfExists('audit_logs');
    }
};
