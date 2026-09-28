<?php

namespace Tests\Feature\Counter;

use App\Support\CompactCounterMarkup;
use Tests\TestCase;

/**
 * Prompt 293 — a counter response is sent without its template indentation, and that may not change what is rendered.
 */
class CompactCounterResponsesTest extends TestCase
{
    public function test_indentation_goes_and_the_page_reads_the_same(): void
    {
        $html = "<div>\n        <span class=\"a\">Dispensación</span>\n\n            <p>uno   dos</p>\n</div>";

        $this->assertSame("<div>\n<span class=\"a\">Dispensación</span>\n<p>uno dos</p>\n</div>", CompactCounterMarkup::strip($html));
    }

    public function test_whitespace_that_is_content_is_left_alone(): void
    {
        $html = "<div>\n    <textarea wire:model=\"voidReason\">línea uno\n    sangrada</textarea>\n    <pre>  a\n    b</pre>\n</div>";

        $stripped = CompactCounterMarkup::strip($html);

        $this->assertStringContainsString("<textarea wire:model=\"voidReason\">línea uno\n    sangrada</textarea>", $stripped);
        $this->assertStringContainsString("<pre>  a\n    b</pre>", $stripped);
    }
}
