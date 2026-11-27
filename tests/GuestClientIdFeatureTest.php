<?php

namespace Ably\LaravelBroadcaster\Tests;

use Illuminate\Foundation\Auth\User;

/**
 * Guests always get a session-stable guest clientId, and a fresh one after logout.
 */
class GuestClientIdFeatureTest extends GuestClientIdFeatureTestCase
{
    public function testGuestIdIsStableAcrossRequestsAndNewAfterLogout()
    {
        $first = $this->authClientId();
        $second = $this->authClientId();

        self::assertMatchesRegularExpression('/^guest:[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{22}$/', $first);
        self::assertSame($first, $second);

        $user = new User;
        $user->id = 42;
        $this->actingAs($user);
        self::assertSame('42', $this->authClientId());

        $this->app['auth']->guard()->logout();
        $afterLogout = $this->authClientId();

        self::assertMatchesRegularExpression('/^guest:[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{22}$/', $afterLogout);
        self::assertNotSame($first, $afterLogout);
    }
}
