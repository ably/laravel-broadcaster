<?php

namespace Ably\LaravelBroadcaster\Tests;

/**
 * The same route with `mau_account` false (the default): guests keep a null clientId.
 */
class GuestClientIdOffFeatureTest extends GuestClientIdFeatureTestCase
{
    protected $mauAccount = false;

    public function testNoGuestIdWhenMauAccountIsOff()
    {
        self::assertNull($this->authClientId());
        self::assertNull($this->authClientId());
    }
}
