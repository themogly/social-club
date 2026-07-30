<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Org-wide strain definition (no price — prices are per location).
        Schema::create('genetics', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignUlid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('thc_bp')->nullable();               // basis points
            $table->integer('cbd_bp')->nullable();
            $table->json('terpenes')->nullable();
            $table->string('cultivation_type')->nullable();       // INDOOR|OUTDOOR|GREENHOUSE
            $table->json('images')->nullable();
            $table->boolean('published')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('organisation_id');
        });

        // Per-location price. tier_id null = the base price. Prompt 08 resolves against this.
        Schema::create('genetic_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('genetic_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('tier_id')->nullable()->constrained('membership_tiers')->cascadeOnDelete();
            $table->bigInteger('price_per_gram_cents');
            $table->unsignedInteger('low_stock_threshold_cg')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['genetic_id', 'location_id']);
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('genetic_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('batch_no');
            $table->date('acquired_or_harvested_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('initial_cg')->default(0);
            $table->bigInteger('remaining_cg')->default(0);
            $table->bigInteger('cost_per_gram_cents')->default(0);
            $table->string('lab_report_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('OPEN');            // OPEN|CLOSED|QUARANTINED
            $table->timestamps();
            $table->softDeletes();
            $table->index(['location_id', 'status']);
            $table->index('genetic_id');
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignUlid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('price_cents')->default(0);
            $table->integer('stock')->default(0);
            $table->integer('low_stock_threshold')->nullable();
            $table->json('images')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['location_id', 'active']);
        });

        Schema::create('stock_takes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->foreignUlid('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->string('status')->default('OPEN');            // OPEN|COMMITTED
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Two explicitly-named quantity columns, never one dual-meaning `qty`.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->ulidMorphs('stockable');                      // Batch | Article
            $table->bigInteger('qty_cg')->nullable();             // batches
            $table->integer('qty_units')->nullable();             // articles
            $table->string('type');                               // INTAKE|DISPENSE|SALE|ADJUSTMENT|MERMA|TRANSFER_IN|TRANSFER_OUT
            $table->string('reason')->nullable();
            $table->foreignUlid('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->foreignUlid('stock_take_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['location_id', 'type', 'created_at']);
        });

        Schema::create('stock_take_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('stock_take_id')->constrained()->cascadeOnDelete();
            $table->ulidMorphs('countable');                      // Batch | Article
            $table->bigInteger('counted_cg')->nullable();
            $table->integer('counted_units')->nullable();
            $table->bigInteger('expected_cg')->nullable();
            $table->integer('expected_units')->nullable();
            $table->bigInteger('variance_cg')->nullable();
            $table->integer('variance_units')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_lines');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_takes');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('genetic_prices');
        Schema::dropIfExists('genetics');
    }
};
