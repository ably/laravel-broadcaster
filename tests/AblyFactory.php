<?php
namespace Ably\LaravelBroadcaster\Tests;

use Ably\AblyRest;
use Ably\LaravelBroadcaster\AblyBroadcaster;

/**
 * Instantiates AblyRest objects
 */
class AblyFactory
{
    /**
     * Make a new AblyRest client
     *
     * @param array|null $clientOptions Options for the created instance
     *
     * @return \Ably\AblyRest
     */
    public function make($clientOptions)
    {
        return $this->createInstance($clientOptions);
    }

    /**
     * Creates a new AblyRest instance
     *
     * @param array|null $clientOptions
     *
     * @return \Ably\AblyRest
     * @throws \Ably\Exceptions\AblyException
     */
    protected function createInstance($clientOptions)
    {
        AblyRest::setAblyAgentHeader('laravel-broadcaster', AblyBroadcaster::LIB_VERSION);
        $laravelVersion = Miscellaneous::getNumeric(app()->version());
        AblyRest::setAblyAgentHeader('laravel', $laravelVersion);
        return new AblyRest($clientOptions);
    }
}
