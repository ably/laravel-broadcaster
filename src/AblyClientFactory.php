<?php

namespace Ably\LaravelBroadcaster;

use Ably\PubSub\PubSubHttpClient;
use Ably\PubSub\Server;

/**
 * Builds the Ably REST client used by the broadcaster from the `ably`
 * connection array in config/broadcasting.php.
 *
 * @internal Not part of the package API; use the `ably` broadcast driver,
 *           or \Ably\PubSub\Server::createHttpClient() directly.
 */
final class AblyClientFactory
{
    private function __construct()
    {
    }

    /**
     * Creates a client declaring the server side (Ably-Agent: … ably-pubsub-server).
     */
    public static function make(array $config): PubSubHttpClient
    {
        return Server::createHttpClient(self::clientOptions($config));
    }

    /**
     * The client options array derived from the broadcasting config.
     */
    public static function clientOptions(array $config): array
    {
        // Only our own registered identifier. A user-supplied `agents` value is
        // overwritten, exactly as 1.x ignored it (1.x had no `agents` option).
        $config['agents'] = ['laravel-broadcaster' => AblyBroadcaster::LIB_VERSION];

        return $config;
    }
}
