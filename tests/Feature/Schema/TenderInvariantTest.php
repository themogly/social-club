<?php

namespace Tests\Feature\Schema;

use App\Models\Dispensation;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TenderInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dispensation_whose_tender_split_does_not_reconcile_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        Dispensation::factory()->create([
            'total_cents' => 1000,
            'cash_cents' => 600,
            'wallet_cents' => 200, // 600 + 200 != 1000
        ]);
    }

    public function test_a_reconciling_dispensation_is_accepted(): void
    {
        $dispensation = Dispensation::factory()->create([
            'total_cents' => 1000,
            'cash_cents' => 700,
            'wallet_cents' => 300,
        ]);

        $this->assertTrue($dispensation->exists);
    }

    public function test_an_order_tender_split_must_reconcile(): void
    {
        $this->expectException(RuntimeException::class);

        Order::factory()->create([
            'total_cents' => 500,
            'cash_cents' => 100,
            'wallet_cents' => 100,
        ]);
    }
}
