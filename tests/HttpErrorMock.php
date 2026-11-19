<?php

namespace Ably\LaravelBroadcaster\Tests;

use Ably\PubSub\Exceptions\AblyRequestException;
use Ably\PubSub\Http;

/**
 * Fails every request with a non-retryable Ably error.
 */
class HttpErrorMock extends Http
{
    public function request($method, $url, $headers = [], $params = [])
    {
        throw new AblyRequestException('boom', 40160, 401);
    }
}
