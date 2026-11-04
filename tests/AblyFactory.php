<?php
namespace Ably\LaravelBroadcaster\Tests;

use Ably\LaravelBroadcaster\AblyClientFactory;

/**
 * Instantiates Ably REST clients the same way the service provider does
 */
class AblyFactory
{
    /**
     * Make a new Ably REST client
     *
     * @param  array  $clientOptions  Options for the created instance
     * @return \Ably\PubSub\PubSubHttpClient
     */
    public function make($clientOptions)
    {
        return $this->createInstance($clientOptions);
    }

    /**
     * Creates a new Ably REST client
     *
     * @param  array  $clientOptions
     * @return \Ably\PubSub\PubSubHttpClient
     *
     * @throws \Ably\PubSub\Exceptions\AblyException
     */
    protected function createInstance($clientOptions)
    {
        return AblyClientFactory::make($clientOptions);
    }
}
