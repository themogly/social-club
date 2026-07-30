<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_middleware_applies_an_enabled_session_locale_and_ignores_others(): void
    {
        Route::middleware('web')->get('/__locale', fn (): string => app()->getLocale());

        $this->withSession(['locale' => 'en'])->get('/__locale')->assertOk()->assertSee('en');
        $this->withSession(['locale' => 'es'])->get('/__locale')->assertOk()->assertSee('es');
        // A disabled/invalid locale is ignored → stays the default (es).
        $this->withSession(['locale' => 'zz'])->get('/__locale')->assertOk()->assertSee('es');
    }

    public function test_ui_strings_render_in_spanish_and_english(): void
    {
        app()->setLocale('es');
        $this->assertSame('Todas las sedes', __('Todas las sedes'));
        $this->assertSame('Personal', __('Personal'));

        app()->setLocale('en');
        $this->assertSame('All locations', __('Todas las sedes'));
        $this->assertSame('Staff', __('Personal'));
    }
}
