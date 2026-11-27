<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\LaravelBroadcaster\LaravelAblyBroadcasterServiceProvider;
use Ably\LaravelBroadcaster\Utils;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * An app upgrading from 1.0.x keeps its config/broadcasting.php, and this package ships no config file, so the
 * `ably` connection has none of the newer keys (`guest_id_prefix`). Drives the real /broadcasting/auth route with
 * only the 1.0.8 keys and asserts the 2.0 defaults: a `guest:` clientId for guests, 8 hr expiry.
 * (`httpClass` and `logLevel` are client options that only keep the test offline.)
 */
class LegacyConfigDefaultsFeatureTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [LaravelAblyBroadcasterServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('broadcasting.default', 'ably');
        $app['config']->set('broadcasting.connections.ably', [
            'driver' => 'ably',
            'key' => 'abcd:efgh',
            'httpClass' => RecordingHttp::class,
            'logLevel' => 0,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Broadcast::routes();
    }

    private function authPayload()
    {
        $response = $this->post('/broadcasting/auth', ['channel_name' => 'public:test']);
        $response->assertOk();

        return Utils::parseJwt($response->json('token'))['payload'];
    }

    public function testGuestGetsAStableGuestClientIdWithoutAnyGuestConfig()
    {
        $first = $this->authPayload()['x-ably-clientId'];

        self::assertMatchesRegularExpression('/^guest:[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{22}$/', $first);
        self::assertSame($first, $this->authPayload()['x-ably-clientId']);
        self::assertSame($first, session('ably_guest_client_id'));
    }

    public function testSignedInUserClientIdIsUnchanged()
    {
        $user = new User;
        $user->id = 7;
        $this->actingAs($user);

        self::assertSame('7', $this->authPayload()['x-ably-clientId']);
    }

    public function testDefaultTokenExpiryIs28800AndPublicChannelsStayOpen()
    {
        $payload = $this->authPayload();

        self::assertSame(28800, $payload['exp'] - $payload['iat']);
        self::assertSame('{"public:*":["subscribe","history","channel-metadata"]}', $payload['x-ably-capability']);
    }
}
