<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\LaravelBroadcaster\AblyBroadcaster;
use Ably\LaravelBroadcaster\Utils;
use Ably\PubSub\PubSubHttpClient;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Log;
use Mockery as m;

/**
 * 1.1 apps may still have `'mau_account' => env('ABLY_MAU_ACCOUNT', ...)` in config/broadcasting.php.
 * 2.0 ignores it: no error, no warning, and guests get a guest clientId whatever its value.
 */
class LeftoverMauAccountConfigFeatureTest extends GuestClientIdFeatureTestCase
{
    const GUEST_ID_PATTERN = '/^guest:[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{22}$/';

    protected $extraConfig = ['mau_account' => false];

    public function testLeftoverMauAccountFalseThroughTheRouteStillIssuesGuestIds()
    {
        Log::spy();

        $first = $this->authClientId();

        self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $first);
        self::assertSame($first, $this->authClientId());
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function testLeftoverMauAccountValueHasNoEffect()
    {
        $ably = m::mock(PubSubHttpClient::class, ['abcd:efgh']);
        foreach ([true, false, 'true', 'false', '0', null] as $value) {
            $broadcaster = new AblyBroadcaster($ably, ['mau_account' => $value]);
            $session = new Store('test', new ArraySessionHandler(10));
            $request = m::mock(Request::class);
            $request->channel_name = 'public:test';
            $request->token = null;
            $request->socket_id = 'abcd.1234';
            $request->shouldReceive('user')->andReturn(null);
            $request->shouldReceive('hasSession')->andReturn(true);
            $request->shouldReceive('session')->andReturn($session);

            $clientId = Utils::parseJwt($broadcaster->auth($request)['token'])['payload']['x-ably-clientId'];

            self::assertMatchesRegularExpression(self::GUEST_ID_PATTERN, $clientId, var_export($value, true));
            self::assertSame($clientId, $session->get('ably_guest_client_id'));
        }
    }
}
