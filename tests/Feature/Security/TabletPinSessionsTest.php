<?php

namespace Tests\Feature\Security;

use App\Actions\Counter\RegisterCounterTerminal;
use App\Actions\Counter\RevokeCounterTerminal;
use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Filament\Pages\Auth\ConfirmIdentity;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\AuditLog;
use App\Models\CounterTerminal;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\CounterTerminals;
use Database\Seeders\RolePermissionSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Concerns\ChangesRolePermissions;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Post-296 audit, Phase 1 findings 5, 6 and 7 — the registered tablet and the PIN.
 *
 * (5) Revoking a stolen tablet did not end the session signed in on it, and the lock stopped signing people out once
 *     the tablet was no longer valid.
 * (6) "Ese PIN ya lo usa otra persona" answered for any PIN, unthrottled: a holder of `staff.manage` could find the
 *     OWNER's PIN and then act as the owner at the counter.
 * (7) A registered tablet plus a PIN opened the whole panel with no password and no MFA. The owner's decision: ask for
 *     the password (and the MFA code where enrolled) the first time a PIN session opens a panel page, then not again
 *     for that person on that tablet for a shift (12 h).
 */
class TabletPinSessionsTest extends TestCase
{
    use ChangesRolePermissions, PostsLivewireOverHttp, RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->owner = $this->person(Role::OWNER, '11119999');
        $this->manager = $this->person(Role::MANAGER, '22228888');
        $this->withCredentials();
    }

    private function person(Role $role, string $pin): User
    {
        $user = User::factory()->create(['pin' => $pin, 'password' => 'secreto-'.$pin]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->centro->id]);

        return $user;
    }

    /** @return array{0: CounterTerminal, 1: string} */
    private function tablet(): array
    {
        ['terminal' => $terminal, 'token' => $token] = (new RegisterCounterTerminal)->handle($this->manager, $this->centro, 'Tablet barra');
        Auth::login($this->manager);
        (new OpenTill)->handle($this->centro, 'POS-1', 10000);
        Auth::logout();

        return [$terminal, CounterTerminals::cookieValue($terminal, $token)];
    }

    private function pinSignIn(string $cookie, string $pin): void
    {
        $this->withCookie(CounterTerminals::COOKIE, $cookie);
        $snapshot = $this->snapshotFrom('/counter/till', 'counter.till-session');
        $this->livewirePost($snapshot, ['operatorPin' => $pin], [['unlockOperator']])->assertOk();
    }

    // --- (5) Revocation ends the session ------------------------------------------------------------------------------

    public function test_revoking_the_tablet_signs_out_whoever_signed_in_on_it(): void
    {
        [$terminal, $cookie] = $this->tablet();
        $this->pinSignIn($cookie, '22228888');
        $this->assertSame($this->manager->id, Auth::id());

        (new RevokeCounterTerminal)->handle($terminal, $this->owner);

        $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/counter');
        $this->assertFalse(Auth::guard('web')->check(), 'the revoked tablet is still signed in');
        $this->assertNull(CounterOperator::id());
    }

    // --- (6) The PIN-taken answer is throttled and audited ----------------------------------------------------------------

    public function test_the_pin_taken_answer_stops_after_five_tries_and_is_audited(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->setRolePermission(Role::MANAGER, 'staff.manage', true);
        app(ActiveScope::class)->setLocation($this->centro->id);
        $taken = __('Ese PIN ya lo usa otra persona. Elige otro.');

        $answers = [];
        foreach (range(1, 7) as $try) {
            $pin = $try === 7 ? '55550000' : '11119999'; // the owner's six times, then a free one
            $errors = Livewire::actingAs($this->manager->fresh())->test(CreateUser::class)
                ->fillForm(['name' => 'Sondeo', 'email' => 'no-es-un-email', 'pin' => $pin, 'password' => 'x'])
                ->call('create')
                ->errors()->get('data.pin');
            $answers[] = $errors[0] ?? null;
        }

        $this->assertSame([$taken, $taken, $taken, $taken, $taken], array_slice($answers, 0, 5));
        $this->assertNotSame($taken, $answers[5], 'the sixth try still says whether the PIN is taken');
        $this->assertSame($answers[5], $answers[6], 'once throttled, a free PIN and a taken one must read the same');
        $this->assertSame(5, AuditLog::query()->where('action', 'user.pin.collision')->count());
        $this->assertStringNotContainsString('11119999', (string) json_encode(AuditLog::query()->where('action', 'user.pin.collision')->get()->toArray()));
    }

    // --- (7) The panel from a PIN session: the password once a shift ----------------------------------------------------

    public function test_a_pin_session_is_asked_for_the_password_before_the_panel_once_a_shift(): void
    {
        [, $cookie] = $this->tablet();
        $this->pinSignIn($cookie, '22228888');

        $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/')->assertRedirect(ConfirmIdentity::getUrl());
        $this->get(ConfirmIdentity::getUrl())->assertOk()->assertSee(route('counter.home'), false); // the way back

        Livewire::test(ConfirmIdentity::class)->fillForm(['password' => 'mal'])->call('confirm')->assertHasFormErrors(['password']);
        $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/')->assertRedirect(ConfirmIdentity::getUrl());

        Livewire::test(ConfirmIdentity::class)->fillForm(['password' => 'secreto-22228888'])->call('confirm')->assertHasNoFormErrors();
        $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/')->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'counter.panel.identity_confirmed')->exists());

        // Locked and back on the same tablet the same shift: not asked again.
        $snapshot = $this->snapshotFrom('/counter/till', 'counter.till-session');
        $this->livewirePost($snapshot, [], [['lockCounter']])->assertOk();
        $this->pinSignIn($cookie, '22228888');
        $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/')->assertOk();

        // The next shift: asked again.
        $this->travel(13)->hours();
        $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/')->assertRedirect(ConfirmIdentity::getUrl());
    }

    public function test_someone_else_on_the_same_tablet_is_asked_for_their_own(): void
    {
        [, $cookie] = $this->tablet();
        $this->pinSignIn($cookie, '22228888');
        Livewire::test(ConfirmIdentity::class)->fillForm(['password' => 'secreto-22228888'])->call('confirm');

        $snapshot = $this->snapshotFrom('/counter/till', 'counter.till-session');
        $this->livewirePost($snapshot, [], [['lockCounter']])->assertOk();
        $this->pinSignIn($cookie, '11119999');

        $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/users')->assertRedirect(ConfirmIdentity::getUrl());
    }

    public function test_an_mfa_user_also_gives_the_code(): void
    {
        $mfa = app(AppAuthentication::class);
        $secret = $mfa->generateSecret();
        $this->manager->saveAppAuthenticationSecret($secret);
        [, $cookie] = $this->tablet();
        $this->pinSignIn($cookie, '22228888');

        Livewire::test(ConfirmIdentity::class)->fillForm(['password' => 'secreto-22228888'])->call('confirm')->assertHasFormErrors();
        $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/')->assertRedirect(ConfirmIdentity::getUrl());

        $code = $mfa->getCurrentCode($this->manager->fresh(), $secret);
        Livewire::test(ConfirmIdentity::class)->fillForm(['password' => 'secreto-22228888', 'code' => $code])->call('confirm')->assertHasNoFormErrors();
        $this->withCookie(CounterTerminals::COOKIE, $cookie)->get('/')->assertOk();
    }

    public function test_the_confirmation_works_over_the_real_endpoint_and_a_stale_panel_page_is_refused(): void
    {
        [, $cookie] = $this->tablet();
        $this->pinSignIn($cookie, '22228888');
        $this->withCookie(CounterTerminals::COOKIE, $cookie);
        $this->get('/')->assertRedirect(ConfirmIdentity::getUrl());

        $snapshot = $this->snapshotFrom(ConfirmIdentity::getUrl(), ConfirmIdentity::class);
        $this->livewirePost($snapshot, ['data.password' => 'secreto-22228888'], [['confirm']])->assertOk();
        $html = (string) $this->get('/')->assertOk()->getContent();

        // A panel page left open into the next shift: its Livewire calls are refused until the password is given again.
        preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
        $panel = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        $this->travel(13)->hours();
        $this->livewirePost($panel)->assertForbidden();
    }

    public function test_a_password_login_is_never_asked(): void
    {
        $this->actingAs($this->manager)->get('/')->assertOk();
    }
}
