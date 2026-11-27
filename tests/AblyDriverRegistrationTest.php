<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\LaravelBroadcaster\AblyBroadcaster;
use Ably\LaravelBroadcaster\LaravelAblyBroadcasterServiceProvider;
use Illuminate\Broadcasting\BroadcastManager;

/**
 * The `ably` driver name is also built into Laravel (and from Laravel 13.41.0 it works with ably/pubsub-server, which
 * this package installs). This package's provider must win, or apps silently get Pusher-protocol auth responses that
 * the `@ably/laravel-echo` client cannot use.
 */
class AblyDriverRegistrationTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [LaravelAblyBroadcasterServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('broadcasting.default', 'ably');
        $app['config']->set('broadcasting.connections.ably', [
            'driver' => 'ably',
            'key' => 'abcd:efgh',
            'httpClass' => RecordingHttp::class,
            'logLevel' => 0,
        ]);
    }

    public function testAblyConnectionResolvesToThisPackagesBroadcaster()
    {
        self::assertInstanceOf(AblyBroadcaster::class, $this->app->make(BroadcastManager::class)->connection('ably'));
        self::assertInstanceOf(AblyBroadcaster::class, $this->app->make(BroadcastManager::class)->connection());
    }
}
