<?php

namespace Tests\Feature\Security;

use Filament\Notifications\Collection;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 363, part 2 — Sentry on production (5 Oct 2026, one event): `Collection::fromLivewire(): Argument #1
 * ($notification) must be of type array, int given` on `/livewire-…/update`. Cause: a request our page never builds. The
 * public /login page renders Filament's Notifications component, so ANY anonymous client can take its snapshot and post an
 * update replacing `notifications` with junk; Filament's Collection trusts the shape and 500s. Neither our code nor
 * Filament's JS writes that property; the same signature (with locked-property updates no browser sends) is
 * filamentphp/filament#19447, closed upstream as not planned. Guarded here: junk items are dropped and logged.
 */
class NotificationsJunkUpdateTest extends TestCase
{
    use PostsLivewireOverHttp, RefreshDatabase;

    public function test_a_junk_update_from_an_anonymous_client_no_longer_500s(): void
    {
        $snapshot = $this->snapshotFrom('/login', 'Filament\\Livewire\\Notifications');

        foreach ([['notifications' => [5]], ['notifications' => ['x' => 5]]] as $updates) {
            $response = $this->livewirePost($snapshot, $updates)->assertOk();
            $notifications = json_decode((string) $response->json('components.0.snapshot'), true)['data']['notifications'][0] ?? null;
            $this->assertSame([], $notifications, 'only valid notifications survive');
        }
    }

    public function test_from_livewire_keeps_valid_notifications_drops_junk_and_logs(): void
    {
        Log::spy();
        $valid = Notification::make('a')->title('Ajuste registrado')->toArray();

        $collection = Collection::fromLivewire(['a' => $valid, 'b' => 5]);

        $this->assertSame(['a'], $collection->keys()->all());
        $this->assertInstanceOf(Notification::class, $collection->get('a'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'notifications')
            && $context['dropped'] === ['int'])->once();
    }
}
