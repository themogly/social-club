<?php

namespace Tests\Unit;

use App\Support\Money;
use App\Support\Weight;
use Tests\TestCase;

class MoneyWeightTest extends TestCase
{
    public function test_round_half_up_rounds_away_from_zero(): void
    {
        $this->assertSame(3.0, round_half_up(2.5));
        $this->assertSame(0.01, round_half_up(0.005, 2));
        $this->assertSame(13.0, round_half_up(12.5));
    }

    /** The canonical money end-to-end assertion: euros at the edge → correct cents. */
    public function test_euros_convert_to_exact_integer_cents(): void
    {
        $this->assertSame(1250, Money::fromEuros('12.50')->cents);
        $this->assertSame(1250, Money::fromEuros('12,50')->cents);   // Spanish decimal comma
        $this->assertSame(1250, Money::fromEuros(12.5)->cents);
        $this->assertSame(125000, Money::fromEuros('1250')->cents);  // €1250.00
        $this->assertSame(1, Money::fromEuros('0.005')->cents);      // half-up at the boundary
    }

    public function test_money_arithmetic_is_integer(): void
    {
        $sum = Money::fromCents(1000)->add(Money::fromCents(250));
        $this->assertSame(1250, $sum->cents);
        $this->assertSame(12.5, Money::fromCents(1250)->euros());
        $this->assertSame(2100, Money::fromCents(700)->multiply(3)->cents);
        $this->assertTrue(Money::fromCents(-5)->isNegative());
    }

    /** Weight end-to-end: grams at the edge → correct centigrams. */
    public function test_grams_convert_to_exact_integer_centigrams(): void
    {
        $this->assertSame(350, Weight::fromGrams('3.5')->centigrams);
        $this->assertSame(350, Weight::fromGrams('3,5')->centigrams);
        $this->assertSame(350, Weight::fromGrams(3.5)->centigrams);
        $this->assertSame(1, Weight::fromGrams('0.005')->centigrams); // half-up
        $this->assertSame(3.5, Weight::fromCentigrams(350)->grams());
    }
}
