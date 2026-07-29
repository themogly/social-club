<?php

namespace Tests\Feature;

use App\Support\SiteContent;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SiteContentTest extends TestCase
{
    public function test_it_caches_plain_arrays_and_reads_with_a_fallback(): void
    {
        $all = SiteContent::all();

        $this->assertIsArray($all);
        $this->assertSame('EUR', SiteContent::get('currency'));

        // Missing/stale key degrades to the default — never a throw.
        $this->assertSame('fallback', SiteContent::get('does_not_exist', 'fallback'));

        // What is cached is a plain array of primitives, never an object.
        $this->assertIsArray(Cache::get('site_content'));
    }

    public function test_flush_busts_the_cache(): void
    {
        SiteContent::all();
        $this->assertTrue(Cache::has('site_content'));

        SiteContent::flush();
        $this->assertFalse(Cache::has('site_content'));
    }
}
