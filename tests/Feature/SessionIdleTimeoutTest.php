<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionNotExpired;
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
 * Exercises the middleware's handle() directly rather than through a
 * multi-request HTTP simulation: $this->get() doesn't reliably carry the
 * same session id across calls in this test environment (see TestCase's
 * own note on that exact fragility), so a real round-trip here would be
 * testing the test client, not the middleware.
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

    private function passThroughMiddleware(): bool
    {
        $passed = false;

        $request = request();
        $request->setLaravelSession(app('session')->driver());

        (new EnsureSessionNotExpired())->handle($request, function ($req) use (&$passed) {
            $passed = true;

            return response('ok');
        });

        return $passed;
    }

    public function test_an_authenticated_request_pushes_the_expiry_forward(): void
    {
        $this->loginWithSession();

        $before = LoginSession::findActiveBySessionId(session()->getId())->expires_at;

        $this->travel(10)->minutes();

        $this->assertTrue($this->passThroughMiddleware());

        $after = LoginSession::findActiveBySessionId(session()->getId())->expires_at;

        $this->assertTrue($after->greaterThan($before));
    }

    public function test_staying_active_the_whole_time_never_times_out(): void
    {
        $this->loginWithSession(30);

        // Three passes, 20 minutes apart — longer than the 30-minute
        // timeout in total, but each one is well within it of the last.
        $this->travel(20)->minutes();
        $this->assertTrue($this->passThroughMiddleware());

        $this->travel(20)->minutes();
        $this->assertTrue($this->passThroughMiddleware());

        $this->travel(20)->minutes();
        $this->assertTrue($this->passThroughMiddleware());
    }

    public function test_sitting_idle_past_the_timeout_signs_you_out(): void
    {
        $this->loginWithSession(30);

        $this->travel(31)->minutes();

        $this->assertFalse($this->passThroughMiddleware());
        $this->assertGuest();
    }

    /**
     * Revoking a device on the Security Center (SettingsManager::revokeDevice())
     * only ever sets revoked_at on the row — this is what actually proves that
     * takes effect: the exact same findActiveBySessionId() lookup this
     * middleware already uses for expiry excludes a revoked row too, so the
     * very next request the revoked session makes gets logged out, same as
     * an expired one. Previously only the DB write itself was asserted,
     * never that it actually rejects the next request.
     */
    public function test_a_revoked_session_is_rejected_on_its_next_request(): void
    {
        $user = $this->loginWithSession();

        $session = LoginSession::findActiveBySessionId(session()->getId());

        $this->assertTrue($this->passThroughMiddleware());

        $session->update(['revoked_at' => now()]);

        $this->assertFalse($this->passThroughMiddleware());
        $this->assertGuest();
    }
}
