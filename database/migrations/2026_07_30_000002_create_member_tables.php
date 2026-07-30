<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('member_no');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('address')->nullable();
            $table->string('photo_path')->nullable();           // private disk
            $table->string('document_type')->nullable();          // DNI|NIE|PASSPORT
            $table->text('document_number')->nullable();          // encrypted at the model
            $table->string('document_scan_path')->nullable();     // private disk
            $table->string('status')->default('APPLICANT');       // APPLICANT|ACTIVE|INACTIVE|EXPIRED|SUSPENDED|EXPELLED
            $table->boolean('is_therapeutic')->default(false);
            $table->foreignUlid('avalador_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('carencia_ends_at')->nullable();
            $table->unsignedInteger('declared_monthly_cg')->nullable();
            $table->unsignedInteger('daily_limit_cg')->nullable();   // per-member override
            $table->unsignedInteger('monthly_limit_cg')->nullable(); // per-member override
            $table->timestamp('sole_association_declared_at')->nullable();
            $table->timestamp('anonymised_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organisation_id', 'member_no']);
            $table->index(['organisation_id', 'status']);
            $table->index(['first_name', 'last_name']);
        });

        // Revocable/regenerable QR card — a row, never a derivation of the member id.
        Schema::create('member_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->index();
            $table->string('purpose')->default('QR_CARD');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('member_applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invite_token_hash')->nullable()->index();
            $table->json('payload')->nullable();
            $table->string('status')->default('PENDING');         // PENDING|APPROVED|REJECTED|WAITING_LIST
            $table->string('reject_reason')->nullable();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUlid('resulting_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamps();
        });

        // One row per consent per version — history cannot live in a scalar column.
        Schema::create('consent_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->string('purpose');
            $table->string('consent_text_version');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('member_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->string('type');                               // ID|CONSENT|DECLARATION|MEDICAL|REGISTRATION_FORM|SANCTION_ACT|OTHER
            $table->string('path');                               // private disk
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignUlid('generated_from')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'type']);
        });

        Schema::create('member_sanctions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->string('type');                               // WARNING|SUSPENSION|EXPULSION
            $table->text('reason')->nullable();
            $table->date('from_date')->nullable();
            $table->date('until_date')->nullable();
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Reads of sensitive documents logged separately from the general audit trail.
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('member_document_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index('member_document_id');
        });

        Schema::create('member_discounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('member_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('discount_id')->nullable()->constrained()->nullOnDelete();
            // Inline custom discount (when discount_id is null).
            $table->string('mode')->nullable();                   // PERCENT|FIXED
            $table->integer('value_bp')->nullable();
            $table->bigInteger('value_cents')->nullable();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_discounts');
        Schema::dropIfExists('document_access_logs');
        Schema::dropIfExists('member_sanctions');
        Schema::dropIfExists('member_documents');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('member_applications');
        Schema::dropIfExists('member_tokens');
        Schema::dropIfExists('members');
    }
};
