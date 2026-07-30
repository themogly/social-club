<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_panel_offers_multi_factor_authentication(): void
    {
        $this->assertTrue(Filament::getPanel('admin')->hasMultiFactorAuthentication());
    }

    public function test_mfa_is_enabled_for_a_user_once_a_secret_is_set(): void
    {
        $provider = AppAuthentication::make();
        $user = User::factory()->create();

        $this->assertFalse($provider->isEnabled($user));

        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->assertTrue($provider->isEnabled($user->fresh()));
        $this->assertNotNull($user->fresh()->mfa_confirmed_at);
    }
}
