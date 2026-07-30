<?php

namespace Tests\Feature\Schema;

use App\Enums\WalletTransactionType;
use App\Models\Batch;
use App\Models\WalletTransaction;
use App\Support\Money;
use App\Support\Weight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CastRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_cast_round_trips_including_zero_large_and_negative(): void
    {
        foreach ([0, 1250, 999_999_999, -1250] as $cents) {
            $created = WalletTransaction::factory()->create([
                'amount_cents' => $cents,
                'balance_after_cents' => $cents,
                'type' => WalletTransactionType::ADJUSTMENT,
            ]);

            // Re-read from the DB (bypassing the org scope — this test is about the
            // cast, not scoping) to prove the round-trip persists.
            $tx = WalletTransaction::withoutGlobalScopes()->findOrFail($created->id);

            $this->assertInstanceOf(Money::class, $tx->amount_cents);
            $this->assertSame($cents, $tx->amount_cents->cents);
            $this->assertSame($cents, $tx->balance_after_cents->cents); // signed/negative persists
        }
    }

    public function test_weight_cast_round_trips(): void
    {
        $created = Batch::factory()->create(['initial_cg' => 350, 'remaining_cg' => 1]);
        $batch = Batch::withoutGlobalScopes()->findOrFail($created->id);

        $this->assertInstanceOf(Weight::class, $batch->initial_cg);
        $this->assertSame(350, $batch->initial_cg->centigrams);   // 3.50 g
        $this->assertSame(3.5, $batch->initial_cg->grams());
        $this->assertSame(1, $batch->remaining_cg->centigrams);   // 0.01 g
        $this->assertSame(0.01, $batch->remaining_cg->grams());
    }

    public function test_weight_addition_does_not_drift_over_a_thousand_additions(): void
    {
        $total = Weight::fromCentigrams(0);
        for ($i = 0; $i < 1000; $i++) {
            $total = $total->add(Weight::fromCentigrams(1));
        }

        $this->assertSame(1000, $total->centigrams); // exact — integer math, no float drift
    }

    /** Pin the exact cents: 1.33 g at €7,49/g, then a 17.5% discount on it. */
    public function test_line_total_and_percentage_discount_pin_exact_cents(): void
    {
        $pricePerGramCents = 749;   // €7,49
        $gramsCg = 133;             // 1.33 g

        $lineTotal = (int) round_half_up($pricePerGramCents * $gramsCg / 100);
        $this->assertSame(996, $lineTotal); // €9,96

        $discount = (int) round_half_up($lineTotal * 1750 / 10_000); // 17.5% = 1750 bp
        $this->assertSame(174, $discount);  // €1,74
    }
}
