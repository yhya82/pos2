<?php

namespace Tests\Feature;

use App\Models\LoginSession;
use App\Models\SecuritySetting;
use App\Models\User;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * EnsureSessionNotExpired is meant to be an idle timeout, not a fixed
 * session length — the Security Score's own suggestion text already
 * promises "sign idle users out after N minutes", so staying active must
 * keep pushing expires_at forward rather than it being set once at login
 * and never touched again.
 *
 * Deliberately doesn't log in through the real Volt flow — that call
 * regenerates the session id internally, which doesn't reliably carry
 * over to this test client's own subsequent requests (see TestCase's own
 * note on this exact fragility). Creating the login_sessions row directly
 * against this test's own, already-stable session id is what actually
 * exercises the middleware across more than one request.
 */
class SessionIdleTimeoutTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function loginWithSession(int $timeoutMinutes = 30): User
    {
        SecuritySetting::current()->update(['session_timeout_minutes' => $timeoutMinutes]);

        $user = User::factory()->create();
        $this->actingAs($user);

        LoginSession::startFor($user, session()->getId(), '127.0.0.1', 'PHPUnit');

        return $user;
    }

    public function test_an_authenticated_request_pushes_the_expiry_forward(): void
    {
        $this->loginWithSession();

        $before = LoginSession::findActiveBySessionId(session()->getId())->expires_at;

        $this->travel(10)->minutes();
        $this->get(route('dashboard'))->assertOk();

        $after = LoginSession::findActiveBySessionId(session()->getId())->expires_at;

        $this->assertTrue($after->greaterThan($before));
    }

    public function test_staying_active_the_whole_time_never_times_out(): void
    {
        $this->loginWithSession(30);

        // Three requests, 20 minutes apart — longer than the 30-minute
        // timeout in total, but each one is well within it of the last.
        $this->travel(20)->minutes();
        $this->get(route('dashboard'))->assertOk();

        $this->travel(20)->minutes();
        $this->get(route('dashboard'))->assertOk();

        $this->travel(20)->minutes();
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_sitting_idle_past_the_timeout_signs_you_out(): void
    {
        $this->loginWithSession(30);

        $this->travel(31)->minutes();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
