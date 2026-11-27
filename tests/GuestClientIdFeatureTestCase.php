<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\LaravelBroadcaster\LaravelAblyBroadcasterServiceProvider;
use Ably\LaravelBroadcaster\Utils;
use Illuminate\Support\Facades\Broadcast;

/**
 * Drives the real /broadcasting/auth route (web middleware, session) offline: the Ably HTTP client is a recorder.
 */
abstract class GuestClientIdFeatureTestCase extends TestCase
{
    /**
     * @var array extra keys merged into the `ably` connection config for the test
     */
    protected $extraConfig = [];

    protected function getPackageProviders($app)
    {
        return [LaravelAblyBroadcasterServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32))); // sessions need an encryption key
        $app['config']->set('broadcasting.default', 'ably');
        $app['config']->set('broadcasting.connections.ably', array_merge([
            'driver' => 'ably',
            'key' => 'abcd:efgh',
            'httpClass' => RecordingHttp::class,
            'logLevel' => 0,
        ], $this->extraConfig));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Broadcast::routes();
    }

    /**
     * @return string|null the clientId of the token returned by /broadcasting/auth
     */
    protected function authClientId()
    {
        $response = $this->post('/broadcasting/auth', ['channel_name' => 'public:test']);
        $response->assertOk();

        return Utils::parseJwt($response->json('token'))['payload']['x-ably-clientId'];
    }
}
