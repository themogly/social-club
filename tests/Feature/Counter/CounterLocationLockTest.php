<?php

namespace Tests\Feature\Counter;

use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TillSession;
use Livewire\Attributes\Locked;
use ReflectionClass;
use Tests\TestCase;

/**
 * Prompt 75 — the counter's working sede (`$locationId`) is resolved in mount() from the operator's OWN
 * available sedes and re-applied as the request scope by the booted hook on EVERY request. If the client
 * could set it, a user could point the counter at a sede they are not assigned to and write there (this was
 * exploited: staff assigned only to sede A wrote a check-in at sede B). So it MUST be #[Locked] on every
 * counter component — this walk fails the moment one loses the attribute.
 */
class CounterLocationLockTest extends TestCase
{
    public function test_every_counter_component_locks_its_working_location(): void
    {
        foreach ([CheckInScreen::class, DispensaryPos::class, BarPos::class, TillSession::class] as $component) {
            $property = (new ReflectionClass($component))->getProperty('locationId');

            $this->assertNotEmpty(
                $property->getAttributes(Locked::class),
                "{$component}::\$locationId must be #[Locked] so the client cannot retarget the counter's sede."
            );
        }
    }
}
