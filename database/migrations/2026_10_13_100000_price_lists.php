<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 382 — three price lists on every batch (Estándar / Local / Personal), chosen by the member's tier.
 *
 *  - membership_tiers.price_list — 'STANDARD' | 'LOCAL' | 'STAFF', default STANDARD. The tier's `discount_bp` no longer
 *    applies to batch prices (the list replaces it); the column stays so history reads, and the tiers that had one are
 *    logged here and shown on *Salud del sistema* for the owner to check;
 *  - batches.local_* / staff_* — nullable: a blank price is the standard one less the list's default % (a setting, 20);
 *  - dispensation_lines.list_rate_cents — the list's own rate (per g or per unit) a line was charged at, frozen with the row
 *    so the receipt reads «8.80 €/g · Local» for ever; null when the standard (with or without a discount) was charged.
 *
 * Members still given a STAFF / LOCAL % discount are logged too (they keep working; *Salud del sistema* lists them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table): void {
            $table->string('price_list', 10)->default('STANDARD');
        });
        Schema::table('batches', function (Blueprint $table): void {
            $table->integer('local_price_per_gram_cents')->nullable();
            $table->integer('local_price_per_eighth_cents')->nullable();
            $table->integer('local_price_per_unit_cents')->nullable();
            $table->integer('staff_price_per_gram_cents')->nullable();
            $table->integer('staff_price_per_eighth_cents')->nullable();
            $table->integer('staff_price_per_unit_cents')->nullable();
        });

        Schema::table('dispensation_lines', function (Blueprint $table): void {
            $table->integer('list_rate_cents')->nullable();
        });

        foreach (DB::table('membership_tiers')->where('discount_bp', '>', 0)->get(['id', 'name', 'discount_bp']) as $tier) {
            Log::notice('Prompt 382: tier «'.$tier->name.'» had a '.($tier->discount_bp / 100).' % discount on batch prices; it now pays its price list instead.', ['tier_id' => $tier->id]);
        }
        foreach (['STAFF', 'LOCAL'] as $kind) {
            $members = DB::table('member_discounts')->join('discounts', 'discounts.id', '=', 'member_discounts.discount_id')
                ->where('discounts.kind', $kind)->distinct()->count('member_discounts.member_id');
            if ($members > 0) {
                Log::notice('Prompt 382: '.$members.' member(s) have a '.$kind.' % discount; move them onto a tier with the matching price list.');
            }
        }
    }

    public function down(): void
    {
        Schema::table('dispensation_lines', function (Blueprint $table): void {
            $table->dropColumn('list_rate_cents');
        });
        Schema::table('batches', function (Blueprint $table): void {
            $table->dropColumn(['local_price_per_gram_cents', 'local_price_per_eighth_cents', 'local_price_per_unit_cents',
                'staff_price_per_gram_cents', 'staff_price_per_eighth_cents', 'staff_price_per_unit_cents']);
        });
        Schema::table('membership_tiers', function (Blueprint $table): void {
            $table->dropColumn('price_list');
        });
    }
};
