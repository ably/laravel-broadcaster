<?php

namespace Ably\LaravelBroadcaster;

use Ably\PubSub\Exceptions\AblyException;
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
     *
     * @throws AblyException 40103 if the `key` is missing or empty
     */
    public static function make(array $config): PubSubHttpClient
    {
        self::assertKey($config);

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
        // The server client always uses basic auth with the API key: with a key, `useTokenAuth` is the only option that
        // would switch ably-php to token auth, and a server-side token without a signed `x-ably-clientType: server`
        // claim is rejected on MAU-billed accounts (error 40167). So it is ignored rather than rejected.
        unset($config['useTokenAuth']);

        return $config;
    }

    /**
     * The broadcaster signs client tokens with the API key, and a server client
     * authenticated by token would need a signed `x-ably-clientType: server`
     * claim (error 40167), so the key is always required, whatever else is set.
     */
    private static function assertKey(array $config): void
    {
        if (! isset($config['key']) || ! is_string($config['key']) || trim($config['key']) === '') {
            throw new AblyException(
                'The Ably broadcaster requires the `key` config (the ABLY_KEY environment variable). '
                .'It signs client tokens with the Ably API key, so server-side token auth '
                .'(`token`, `authUrl`, `authCallback`) is not supported.',
                40103,
                401
            );
        }
    }
}
