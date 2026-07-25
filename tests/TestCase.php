<?php

namespace Tests;

use App\Http\Middleware\EnsureSessionNotExpired;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Plain `actingAs()` fakes the auth guard but never creates the
     * login_sessions row the real Volt login flow does, and
     * EnsureSessionNotExpired 302s every request for an authenticated user
     * with no matching row for the current session ID — trying to
     * reverse-engineer a session ID that will match a real HTTP test
     * request turned out to be exactly the kind of framework-internals
     * fragility not worth chasing. Since this middleware has its own
     * dedicated coverage (LoginSession is exercised directly through the
     * real login flow in AuthenticationTest), skipping it here for tests
     * that aren't *about* session expiry is the standard, reliable way to
     * fake auth for an HTTP-level test.
     */
    protected function actingAsUser(User $user): static
    {
        $this->withoutMiddleware(EnsureSessionNotExpired::class);

        return $this->actingAs($user);
    }
}
