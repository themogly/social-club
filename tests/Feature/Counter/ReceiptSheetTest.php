<?php

namespace Tests\Feature\Counter;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Prompt 252 — the receipt is a sheet inside the counter, from ONE component, on BOTH POS screens.
 *
 * A new tab is a hidden tab on a tablet; the ticket opens as a modal over the work with the unchanged receipt
 * route in an iframe. This pins: both POS screens consume the one `x-counter.receipt-sheet` (a third cannot
 * hand-roll a second), the sheet is closed by default, and its iframe points at the receipt route it was given.
 */
class ReceiptSheetTest extends TestCase
{
    /** The counter POS screens that show a receipt after a commit. A new one must be added here. */
    private const POS_VIEWS = [
        'resources/views/livewire/counter/dispensary-pos.blade.php',
        'resources/views/livewire/counter/bar-pos.blade.php',
    ];

    public function test_the_component_exists(): void
    {
        $this->assertFileExists(resource_path('views/components/counter/receipt-sheet.blade.php'));
    }

    public function test_both_pos_screens_consume_the_one_sheet_component_and_open_no_tab(): void
    {
        foreach (self::POS_VIEWS as $view) {
            $source = (string) file_get_contents(base_path($view));

            $this->assertStringContainsString('<x-counter.receipt-sheet', $source,
                "$view does not use the shared receipt sheet — it must not hand-roll its own");
            // The receipt is no longer opened in a new tab.
            $this->assertStringNotContainsString('target="_blank"', $source,
                "$view still opens something in a new tab");
        }
    }

    public function test_the_sheet_is_closed_by_default_and_its_iframe_points_at_the_receipt_route(): void
    {
        $url = 'http://localhost/counter/pos/receipt/01jtestreceiptulid00000000';

        $html = Blade::render('<x-counter.receipt-sheet :url="$url" :label="$label" />', [
            'url' => $url,
            'label' => 'Ver / imprimir recibo',
        ]);

        // The overlay is present but toggled by x-show (closed on arrival), never x-if (prompt 245).
        $this->assertStringContainsString('data-receipt-sheet', $html);
        $this->assertStringContainsString('x-show="isOpen"', $html);
        $this->assertStringNotContainsString('<template', $html, 'the sheet uses x-if — it must use x-show');

        // The trigger, the print and the close are all present and labelled.
        $this->assertStringContainsString('data-receipt-open', $html);
        $this->assertStringContainsString('data-receipt-print', $html);
        $this->assertStringContainsString('data-receipt-close', $html);

        // The iframe is bound to a src the component drives, and the base it was given is that receipt route
        // (the URL is @js-encoded, so slashes are escaped — the distinctive receipt id proves its identity).
        $this->assertStringContainsString('data-receipt-frame', $html);
        $this->assertStringContainsString('x-bind:src="src"', $html);
        $this->assertStringContainsString('01jtestreceiptulid00000000', $html, 'the iframe base is not the receipt route it was given');
        $this->assertMatchesRegularExpression('#counter\\\\?/pos\\\\?/receipt#', $html, 'the base is not the receipt route');

        // The Android back gesture closes the sheet rather than leaving the page.
        $this->assertStringContainsString('history.pushState', $html);
        $this->assertStringContainsString('popstate', $html);
    }
}
