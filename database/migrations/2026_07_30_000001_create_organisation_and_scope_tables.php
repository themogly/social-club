<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_id')->nullable();            // CIF/NIF
            $table->string('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->unsignedInteger('capacity')->nullable();   // aforo
            $table->string('timezone')->default('Europe/Madrid');
            $table->time('business_day_cutoff')->default('06:00');
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
            $table->string('accent')->nullable();               // per-location UI accent (prompt 03)
            $table->json('settings')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organisation_id', 'active']);
        });

        // Staff ↔ location assignment. Internal pivot — integer pk is fine.
        Schema::create('location_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['location_id', 'user_id']);
        });

        // Keyed, typed settings store. Resolves location -> organisation -> code default
        // via the Settings accessor. Prompt 03 owns the UI; the table lives here.
        Schema::create('settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type')->default('STRING');          // STRING|INT|BOOL|FLOAT|JSON|CENTS|CG|BP
            $table->timestamps();
            $table->unique(['organisation_id', 'location_id', 'key']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('applies_to');                        // GENETIC|ARTICLE
            $table->timestamps();
            $table->index(['organisation_id', 'applies_to']);
        });

        Schema::create('document_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->longText('body')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('default_kind')->default('OVERHEAD'); // TILL|OVERHEAD
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('membership_tiers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('default_fee_cents')->default(0);
            $table->string('default_period')->default('YEARLY'); // MONTHLY|YEARLY|LIFETIME|CUSTOM
            $table->text('benefits')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind');                              // STAFF|LOCAL|CONCESSION|THERAPEUTIC|CUSTOM
            $table->string('mode');                              // PERCENT|FIXED
            $table->integer('value_bp')->nullable();             // when PERCENT (basis points)
            $table->bigInteger('value_cents')->nullable();       // when FIXED
            $table->string('applies_to')->default('BOTH');       // GENETIC|ARTICLE|BOTH
            $table->foreignUlid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Per-location enablement of a discount. Internal pivot.
        Schema::create('discount_location', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['discount_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_location');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('membership_tiers');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('location_user');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('organisations');
    }
};
